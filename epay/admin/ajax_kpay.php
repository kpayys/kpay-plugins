<?php
/**
 * 管理后台：KPay 服务商工具接口
 */
include("../includes/common.php");
if($islogin==1){}else exit('{"code":-3,"msg":"No Login"}');
if(!checkRefererHost())exit('{"code":403}');
require_once PLUGIN_ROOT.'kpay/inc/KpayAjax.php';

$act = isset($_GET['act']) ? (string)$_GET['act'] : '';

kpay_ajax_run(function () use ($act) {
	global $DB;
	$service = KpayService::instance();
	$service->install();

	switch ($act) {
		case 'settings':
			$settings = $service->settings();
			$channels = $DB->getAll("SELECT id,name,type,status FROM pre_channel WHERE plugin='kpay' ORDER BY id ASC");
			return ['code' => 0, 'data' => [
				'settings'    => [
					'apiurl'           => $settings['apiurl'],
					'apikey'           => $settings['apikey'],
					'hasSecret'        => $settings['apisecret'] !== '',
					'callback_channel' => (int)$settings['callback_channel'],
					'providers'        => $settings['providers'],
					'package_code'     => $settings['package_code'],
					'bind_channels'    => $settings['bind_channels'],
					'self_service'     => (int)$settings['self_service'],
					'user_bind'        => (int)$settings['user_bind'],
					'extra_create'     => $settings['extra_create'],
				],
				'channels'    => $channels,
				'callbackUrl' => $service->callbackUrl(),
				'warnings'    => $service->bindChannelWarnings(),
			]];

		case 'settings_save':
			$warnings = $service->saveSettings($_POST);
			return ['code' => 0, 'msg' => $warnings ? implode('；', $warnings) : '保存成功', 'data' => ['warnings' => $warnings]];

		case 'options':
			$options = $service->options();
			$providers = [];
			foreach (isset($options['providers']) ? $options['providers'] : [] as $item) {
				$providers[] = [
					'provider'      => isset($item['provider']) ? $item['provider'] : '',
					'label'         => isset($item['providerLabel']) ? $item['providerLabel'] : '',
					'ready'         => !empty($item['ready']),
					'subjectTypes'  => isset($item['supportedSubjectTypes']) ? $item['supportedSubjectTypes'] : [],
				];
			}
			$packages = [];
			foreach (isset($options['packageOptions']) ? $options['packageOptions'] : [] as $item) {
				$packages[] = [
					'code'      => isset($item['code']) ? $item['code'] : '',
					'label'     => isset($item['label']) ? $item['label'] : '',
					'providers' => isset($item['defaultProviders']) ? $item['defaultProviders'] : [],
				];
			}
			return ['code' => 0, 'msg' => '连接成功', 'data' => ['providers' => $providers, 'packages' => $packages]];

		case 'list':
			$page = max(1, (int)(isset($_POST['page']) ? $_POST['page'] : 1));
			$size = 20;
			$where = '1=1';
			$params = [];
			$keyword = trim((string)(isset($_POST['keyword']) ? $_POST['keyword'] : ''));
			if ($keyword !== '') {
				if (ctype_digit($keyword)) {
					$where .= ' AND A.uid=:uid';
					$params[':uid'] = (int)$keyword;
				} else {
					$where .= ' AND (A.merchant_name LIKE :kw OR A.application_no=:no)';
					$params[':kw'] = '%' . $keyword . '%';
					$params[':no'] = $keyword;
				}
			}
			$status = trim((string)(isset($_POST['status']) ? $_POST['status'] : ''));
			if ($status !== '') {
				$where .= ' AND A.status=:status';
				$params[':status'] = $status;
			}
			$total = (int)$DB->getColumn("SELECT COUNT(*) FROM pre_kpay_apply A WHERE {$where}", $params);
			$offset = ($page - 1) * $size;
			$rows = $DB->getAll("SELECT A.id,A.uid,A.application_no,A.merchant_name,A.status,A.status_label,A.activation_status,A.merchant_code,A.bind_pid,A.addtime,A.updatetime,B.username FROM pre_kpay_apply A LEFT JOIN pre_user B ON A.uid=B.uid WHERE {$where} ORDER BY A.id DESC LIMIT {$offset},{$size}", $params);
			return ['code' => 0, 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $size]];

		case 'apply_detail_admin':
			$row = $service->getApply(isset($_POST['id']) ? $_POST['id'] : 0);
			if (!$row || !$row['application_no']) {
				throw new Exception('进件单不存在');
			}
			$view = !empty($_POST['sync']) ? $service->sync($row) : $service->remoteView($row['application_no']);
			$providers = [];
			foreach (isset($view['providerStatuses']) ? $view['providerStatuses'] : [] as $item) {
				$providers[] = [
					'label'        => isset($item['providerLabel']) ? $item['providerLabel'] : (isset($item['provider']) ? $item['provider'] : ''),
					'status'       => isset($item['lifecycleStatusLabel']) && $item['lifecycleStatusLabel'] !== '' ? $item['lifecycleStatusLabel'] : (isset($item['status']) ? $item['status'] : ''),
					'merchantCode' => isset($item['remoteMerchantCode']) ? $item['remoteMerchantCode'] : '',
					'remark'       => isset($item['remoteReviewRemark']) && $item['remoteReviewRemark'] !== '' ? $item['remoteReviewRemark'] : (isset($item['remoteLastError']) ? $item['remoteLastError'] : ''),
					'signUrl'      => isset($item['remoteSignUrl']) ? $item['remoteSignUrl'] : '',
				];
			}
			$merchant = KpayService::merchantView($view);
			return ['code' => 0, 'data' => [
				'apply'     => $service->getApply($row['id']),
				'merchant'  => $merchant,
				'providers' => $providers,
			]];

		case 'sp':
			$resource = isset($_POST['resource']) ? (string)$_POST['resource'] : '';
			return ['code' => 0, 'data' => $service->serviceProvider($resource, $_POST)];

		case 'rate_submit':
			$result = $service->submitRateChange($_POST);
			return ['code' => 0, 'msg' => '已提交，等待平台处理', 'data' => $result];

		case 'rate_cancel':
			$service->cancelRateChange(isset($_POST['id']) ? $_POST['id'] : 0);
			return ['code' => 0, 'msg' => '已撤回'];
	}

	// 其余动作是进件表单，管理员代指定商户操作
	if (strpos($act, 'apply_') === 0) {
		$uid = (int)(isset($_POST['uid']) ? $_POST['uid'] : 0);
		if ($uid <= 0 || !$DB->getColumn("SELECT uid FROM pre_user WHERE uid=:uid LIMIT 1", [':uid' => $uid])) {
			throw new Exception('商户不存在');
		}
		return kpay_apply_action($service, substr($act, 6), $uid, true);
	}
	throw new Exception('不支持的操作');
});
