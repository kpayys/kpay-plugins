<?php

require_once __DIR__ . '/KpayService.php';

/**
 * 进件表单的 ajax 动作，商户中心和管理后台共用
 *
 * @param bool $isAdmin 管理员代商户操作时为 true
 * @return array
 */
function kpay_apply_action(KpayService $service, $act, $uid, $isAdmin)
{
	$uid = (int)$uid;
	$row = $uid > 0 ? $service->latestForUser($uid) : null;

	$requireRow = function () use ($row) {
		if (!$row || !$row['application_no']) {
			throw new Exception('请先填写并保存资料');
		}
		return $row;
	};
	$requireWritable = function () use ($service, $isAdmin) {
		if (!$service->configured()) {
			throw new Exception('进件功能尚未配置');
		}
		if (!$isAdmin && !$service->setting('self_service')) {
			throw new Exception('暂未开放在线申请，请联系平台');
		}
	};

	switch ($act) {
		case 'detail':
			return kpay_apply_detail($service, $row, $isAdmin);

		case 'save':
			$requireWritable();
			$form = isset($_POST['form']) && is_array($_POST['form']) ? $_POST['form'] : [];
			$service->saveDraft($uid, $form, !empty($_POST['startNew']));
			return kpay_apply_detail($service, $service->latestForUser($uid), $isAdmin, '资料已保存');

		case 'upload':
			$requireWritable();
			$current = $requireRow();
			$materialType = isset($_POST['materialType']) ? (string)$_POST['materialType'] : '';
			$file = isset($_FILES['file']) ? $_FILES['file'] : [];
			$result = $service->uploadMaterial($current, $materialType, $file);
			$message = '上传成功';
			if ($result['ocrStatus'] === 'parsed') {
				$message = '上传成功，已自动识别并回填信息';
			}
			return kpay_apply_detail($service, $service->latestForUser($uid), $isAdmin, $message);

		case 'bank':
			$requireWritable();
			$current = $requireRow();
			$form = isset($_POST['form']) && is_array($_POST['form']) ? $_POST['form'] : [];
			$result = $service->resolveBankCard($current, $form);
			$message = !empty($result['complete']) ? '已识别开户行' : (!empty($result['warning']) ? $result['warning'] : '未能完整识别，请手动填写支行和联行号');
			return kpay_apply_detail($service, $service->latestForUser($uid), $isAdmin, $message);

		case 'submit':
			$requireWritable();
			$service->submit($requireRow());
			return kpay_apply_detail($service, $service->latestForUser($uid), $isAdmin, '已提交审核');

		case 'sync':
			$current = $requireRow();
			// 商户端刷新限频，避免频繁打到机构接口
			if ($isAdmin || !$current['updatetime'] || time() - strtotime($current['updatetime']) >= 30) {
				$service->sync($current);
			}
			return kpay_apply_detail($service, $service->latestForUser($uid), $isAdmin, '状态已刷新');

		case 'regions':
			$province = isset($_POST['provinceCode']) ? preg_replace('/\D/', '', $_POST['provinceCode']) : '';
			$city = isset($_POST['cityCode']) ? preg_replace('/\D/', '', $_POST['cityCode']) : '';
			return ['code' => 0, 'data' => $service->regions($province, $city)];

		case 'bind':
			$current = $requireRow();
			if (!$isAdmin && !$service->setting('user_bind')) {
				throw new Exception('请联系平台完成收款账户绑定');
			}
			if (!$isAdmin && !in_array($current['status'], ['approved', 'partial_approved', 'partially_approved'], true)) {
				throw new Exception('进件审核通过后才能绑定');
			}
			$count = $service->bind($current, isset($_POST['pid']) ? $_POST['pid'] : '', isset($_POST['key']) ? $_POST['key'] : '');
			return kpay_apply_detail($service, $service->latestForUser($uid), $isAdmin, '已绑定，生成 ' . $count . ' 个收款通道');
	}
	throw new Exception('不支持的操作');
}

function kpay_apply_detail(KpayService $service, $row, $isAdmin, $message = '')
{
	$data = [
		'configured'  => $service->configured(),
		'selfService' => (bool)$service->setting('self_service') || $isAdmin,
		'userBind'    => (bool)$service->setting('user_bind') || $isAdmin,
		'hasAlipay'   => in_array('alipay', (array)$service->setting('providers'), true),
		'materials'   => KpayService::MATERIAL_TYPES,
		'apply'       => null,
		'view'        => null,
		'canStartNew' => KpayService::canStartNew($row),
	];
	if ($row) {
		$data['apply'] = [
			'id'               => (int)$row['id'],
			'status'           => $row['status'],
			'statusLabel'      => $row['status_label'] ?: KpayService::statusLabel($row['status']),
			'activationStatus' => $row['activation_status'],
			'bindPid'          => $row['bind_pid'],
			'boundAt'          => $row['bound_at'],
			'updatetime'       => $row['updatetime'],
		];
		if ($isAdmin) {
			$data['apply']['applicationNo'] = $row['application_no'];
			$data['apply']['merchantCode'] = $row['merchant_code'];
		}
		if ($row['application_no'] && $service->configured()) {
			try {
				$data['view'] = KpayService::merchantView($service->remoteView($row['application_no']));
			} catch (Exception $e) {
				$data['viewError'] = $e->getMessage();
			}
		}
	}
	return ['code' => 0, 'msg' => $message, 'data' => $data];
}

/**
 * ajax 入口的公共处理：CSRF、JSON 输出、异常转成错误信息
 */
function kpay_ajax_run($callback)
{
	@header('Content-Type: application/json; charset=UTF-8');
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		exit(json_encode(['code' => -1, 'msg' => '请求方式错误']));
	}
	if (empty($_SESSION['kpay_csrf']) || !isset($_POST['csrf']) || !hash_equals($_SESSION['kpay_csrf'], (string)$_POST['csrf'])) {
		exit(json_encode(['code' => -1, 'msg' => '页面已过期，请刷新后重试']));
	}
	try {
		$result = $callback();
	} catch (Exception $e) {
		$result = ['code' => -1, 'msg' => $e->getMessage()];
	}
	exit(json_encode($result, JSON_UNESCAPED_UNICODE));
}

function kpay_csrf_token()
{
	if (empty($_SESSION['kpay_csrf'])) {
		$_SESSION['kpay_csrf'] = bin2hex(random_bytes(16));
	}
	return $_SESSION['kpay_csrf'];
}
