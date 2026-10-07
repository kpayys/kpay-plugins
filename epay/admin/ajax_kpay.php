<?php
/**
 * 管理后台：KPay 服务商工具接口
 */
include("../includes/common.php");
if($islogin==1){}else exit('{"code":-3,"msg":"No Login"}');
if(!checkRefererHost())exit('{"code":403}');
require_once PLUGIN_ROOT.'kpay/inc/KpayAjax.php';

$act = isset($_GET['act']) ? (string)$_GET['act'] : '';

// 对账单是文件下载，走 GET，不套 JSON 外壳
if ($act === 'statement') {
	if (empty($_SESSION['kpay_csrf']) || !isset($_GET['csrf']) || !hash_equals($_SESSION['kpay_csrf'], (string)$_GET['csrf'])) {
		exit('页面已过期，请刷新后重试');
	}
	$date = isset($_GET['date']) ? (string)$_GET['date'] : '';
	try {
		$csv = KpayService::instance()->dailyStatement($date);
	} catch (Exception $e) {
		@header('Content-Type: text/plain; charset=UTF-8');
		exit('下载失败：' . $e->getMessage());
	}
	@header('Content-Type: text/csv; charset=UTF-8');
	@header('Content-Disposition: attachment; filename="kpay-statement-' . preg_replace('/[^0-9-]/', '', $date) . '.csv"');
	exit($csv);
}

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
					'bind_mode'        => $settings['bind_mode'] === 'epay_key' ? 'epay_key' : 'delegate',
				],
				'channels'    => $channels,
				'callbackUrl' => $service->callbackUrl(),
				'webhookUrl'  => $service->webhookUrl(),
				'webhookOn'   => $settings['webhook_url'] !== '' && $settings['webhook_secret'] !== '',
				'warnings'    => $service->bindChannelWarnings(),
			]];

		case 'webhook_status':
			return ['code' => 0, 'data' => $service->webhookStatus()];

		case 'webhook_enable':
			$result = $service->enableWebhook(!empty($_POST['force']));
			if (!$result['configured']) {
				return ['code' => 0, 'msg' => '', 'data' => $result];
			}
			return ['code' => 0, 'msg' => '已开启事件推送', 'data' => $result];

		case 'webhook_disable':
			$service->disableWebhook();
			return ['code' => 0, 'msg' => '已关闭事件推送'];

		case 'webhook_test':
			$service->testWebhook();
			return ['code' => 0, 'msg' => '已发送测试事件，几秒后刷新查看最近送达时间'];

		case 'auth_request':
			$result = $service->requestAuthorization(isset($_POST['bindingId']) ? $_POST['bindingId'] : 0);
			return ['code' => 0, 'msg' => '已发起申请，等待商户在 KPay 同意', 'data' => $result];

		case 'auth_cancel':
			$service->cancelAuthorization(isset($_POST['bindingId']) ? $_POST['bindingId'] : 0);
			return ['code' => 0, 'msg' => '已撤回，代收子通道已停用'];

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
			$rows = $DB->getAll("SELECT A.id,A.uid,A.application_no,A.merchant_name,A.status,A.status_label,A.activation_status,A.merchant_code,A.bind_pid,A.sp_binding_id,A.auth_status,A.addtime,A.updatetime,B.username FROM pre_kpay_apply A LEFT JOIN pre_user B ON A.uid=B.uid WHERE {$where} ORDER BY A.id DESC LIMIT {$offset},{$size}", $params);
			return ['code' => 0, 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $size]];

		case 'apply_detail_admin':
			$row = $service->getApply(isset($_POST['id']) ? $_POST['id'] : 0);
			if (!$row || !$row['application_no']) {
				throw new Exception('进件单不存在');
			}
			$view = !empty($_POST['sync']) ? $service->sync($row) : $service->remoteView($row['application_no']);
			$row = kpay_refresh_authorization($service, $service->getApply($row['id']), !empty($_POST['sync']) ? 0 : 30);
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
			$apply = $service->getApply($row['id']);
			$apply['auth_label'] = $apply['sp_binding_id'] ? KpayService::authLabel((string)$apply['auth_status']) : '';
			return ['code' => 0, 'data' => [
				'apply'     => $apply,
				'merchant'  => $merchant,
				'providers' => $providers,
				'bindMode'  => $service->setting('bind_mode') === 'epay_key' ? 'epay_key' : 'delegate',
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
