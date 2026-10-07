<?php

require_once __DIR__ . '/KpayClient.php';

/**
 * KPay 服务商进件模块（彩虹易支付）
 *
 * 数据只在本地保存进件单号、状态和绑定关系；商户的证件、银行卡等资料全部交给 KPay 保存，
 * 页面需要时实时从 KPay 读取。
 */
class KpayService
{
	const SETTING_KEY = 'kpay_sp';
	const DOMAIN_VERIFY_FILE = 'kpay-domain-verification.txt';
	const DB_VERSION = '2';

	// 代商户收款的子通道：商户ID写成 sp:<渠道商户ID>，下单时改用服务商密钥 + X-KPay-Sub-Merchant
	const DELEGATE_PREFIX = 'sp:';
	const DELEGATE_KEY = 'sp';

	// 本站通过事件推送关心的事件
	const WEBHOOK_EVENTS = ['onboarding.status_changed', 'merchant.authorization_changed'];

	const AUTH_LABELS = [
		'pending'  => '等待商户同意',
		'active'   => '已授权',
		'rejected' => '商户已拒绝',
		'revoked'  => '授权已撤回',
		'canceled' => '申请已撤回',
		'none'     => '未申请',
	];

	// 商户可以填写的资料字段，保存进件单时只会写入这些字段
	const FORM_FIELDS = [
		'subjectType', 'merchantName', 'contactMobile', 'contactEmail',
		'industryKeyword', 'industryCodePreferred',
		'provinceCode', 'cityCode', 'countyCode', 'addressDetail',
		'legalName', 'legalIdNumber', 'legalIdValidFrom', 'legalIdValidUntil',
		'bankAccountName', 'bankAccountNo', 'bankName', 'bankBranchName', 'bankProvince', 'bankCity', 'settlementInterBankNo',
		'businessLicenseNumber', 'companyName', 'alipayLogonId',
	];

	const MATERIAL_TYPES = [
		'id_card_front'    => '身份证人像面',
		'id_card_back'     => '身份证国徽面',
		'holding_id'       => '手持身份证',
		'bank_card_front'  => '银行卡正面',
		'bank_card_back'   => '银行卡反面',
		'business_license' => '营业执照',
		'store_signboard'  => '门头照',
		'store_indoor'     => '店内照',
		'cashier'          => '收银台照',
		'signature'        => '签名照',
		'paper_agreement'  => '纸质协议',
	];

	const STATUS_LABELS = [
		'draft'              => '草稿',
		'pending_payment'    => '待支付',
		'submitting'         => '提交中',
		'submitted'          => '审核中',
		'partial_submitted'  => '部分提交',
		'partially_submitted'=> '部分提交',
		'approved'           => '已通过',
		'partial_approved'   => '部分通过',
		'partially_approved' => '部分通过',
		'rejected'           => '已退回',
		'partial_rejected'   => '部分退回',
		'partially_rejected' => '部分退回',
		'closed'             => '已关闭',
	];

	private static $instance;
	private $settings;

	public static function instance()
	{
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// 测试时替换成带假客户端的实例
	public static function setInstance($service)
	{
		self::$instance = $service;
	}

	// ─── 设置 ───────────────────────────────────────────────────────

	public function settings()
	{
		global $DB;
		if ($this->settings === null) {
			$raw = $DB->getColumn("SELECT v FROM pre_config WHERE k=:k LIMIT 1", [':k' => self::SETTING_KEY]);
			$data = $raw ? json_decode($raw, true) : [];
			$this->settings = array_merge(self::defaultSettings(), is_array($data) ? $data : []);
		}
		return $this->settings;
	}

	public static function defaultSettings()
	{
		return [
			'apiurl'           => KpayClient::DEFAULT_GATEWAY,
			'apikey'           => '',
			'apisecret'        => '',
			'callback_channel' => 0,
			'providers'        => [],
			'package_code'     => '',
			'bind_channels'    => [],
			'self_service'     => 0,
			'user_bind'        => 0,
			'extra_create'     => '',
			'callback_secret'  => '',
			// delegate：服务商代收，商户只需在 KPay 同意授权；epay_key：商户自己建 EPay 密钥并验证本站域名
			'bind_mode'        => 'delegate',
			'webhook_url'      => '',
			'webhook_secret'   => '',
		];
	}

	public function setting($key)
	{
		$settings = $this->settings();
		return isset($settings[$key]) ? $settings[$key] : null;
	}

	public function saveSettings(array $input)
	{
		global $DB;
		$current = $this->settings();
		$next = $current;

		$next['apiurl'] = KpayClient::normalizeGateway(isset($input['apiurl']) ? $input['apiurl'] : '');
		$next['apikey'] = trim((string)(isset($input['apikey']) ? $input['apikey'] : ''));
		// Secret 留空表示不修改
		if (isset($input['apisecret']) && trim($input['apisecret']) !== '') {
			$next['apisecret'] = trim($input['apisecret']);
		}
		$next['callback_channel'] = (int)(isset($input['callback_channel']) ? $input['callback_channel'] : 0);
		$next['providers'] = self::cleanList(isset($input['providers']) ? $input['providers'] : [], '/^[a-z_]{2,32}$/');
		$next['package_code'] = trim((string)(isset($input['package_code']) ? $input['package_code'] : ''));
		$next['bind_channels'] = array_values(array_map('intval', self::cleanList(isset($input['bind_channels']) ? $input['bind_channels'] : [], '/^\d+$/')));
		$next['self_service'] = empty($input['self_service']) ? 0 : 1;
		$next['user_bind'] = empty($input['user_bind']) ? 0 : 1;
		$next['bind_mode'] = isset($input['bind_mode']) && $input['bind_mode'] === 'epay_key' ? 'epay_key' : 'delegate';

		$extra = trim((string)(isset($input['extra_create']) ? $input['extra_create'] : ''));
		if ($extra !== '') {
			$decoded = json_decode($extra, true);
			if (!is_array($decoded)) {
				throw new Exception('建单附加参数必须是 JSON 对象');
			}
		}
		$next['extra_create'] = $extra;

		if ($next['callback_secret'] === '') {
			$next['callback_secret'] = bin2hex(random_bytes(24));
		}

		foreach (array_merge([$next['callback_channel']], $next['bind_channels']) as $channelId) {
			if ($channelId <= 0) {
				continue;
			}
			$channel = $DB->getRow("SELECT id,plugin,config FROM pre_channel WHERE id=:id LIMIT 1", [':id' => $channelId]);
			if (!$channel || $channel['plugin'] !== 'kpay') {
				throw new Exception('通道 #' . $channelId . ' 不是 KPay 通道');
			}
		}

		$DB->exec("REPLACE INTO pre_config SET k=:k, v=:v", [':k' => self::SETTING_KEY, ':v' => json_encode($next, JSON_UNESCAPED_UNICODE)]);
		$this->settings = $next;
		return $this->bindChannelWarnings();
	}

	/**
	 * 绑定用的通道密钥必须写成 [appid]/[appkey] 占位，子通道里的值才会生效
	 */
	public function bindChannelWarnings()
	{
		global $DB;
		$warnings = [];
		foreach ($this->setting('bind_channels') as $channelId) {
			$channel = $DB->getRow("SELECT id,name,config FROM pre_channel WHERE id=:id LIMIT 1", [':id' => $channelId]);
			$config = $channel && $channel['config'] ? json_decode($channel['config'], true) : [];
			if (!is_array($config) || !isset($config['appid'], $config['appkey']) || $config['appid'] !== '[appid]' || $config['appkey'] !== '[appkey]') {
				$warnings[] = '通道「' . ($channel ? $channel['name'] : $channelId) . '」的密钥配置里，商户ID要填 [appid]、商户密钥要填 [appkey]，否则子通道不生效';
			}
		}
		return $warnings;
	}

	public function configured()
	{
		return $this->setting('apikey') !== '' && $this->setting('apisecret') !== '' && count($this->setting('providers')) > 0;
	}

	public function client()
	{
		return new KpayClient($this->setting('apiurl'), $this->setting('apikey'), $this->setting('apisecret'));
	}

	// 不带 API Key 的客户端，只调 EPay 接口
	public function epayClient()
	{
		return new KpayClient($this->setting('apiurl'));
	}

	public function callbackUrl()
	{
		return $this->channelNotifyUrl('applynotify');
	}

	/**
	 * 服务商事件推送的接收地址，和进件回调共用「回调通道」
	 */
	public function webhookUrl()
	{
		return $this->channelNotifyUrl('spnotify');
	}

	private function channelNotifyUrl($action)
	{
		global $siteurl, $conf;
		$channelId = (int)$this->setting('callback_channel');
		if ($channelId <= 0) {
			return '';
		}
		$base = !empty($conf['localurl']) ? $conf['localurl'] : $siteurl;
		return $base . 'pay/' . $action . '/' . $channelId . '/';
	}

	// ─── 本地进件单表 ───────────────────────────────────────────────

	public function install()
	{
		global $DB;
		$version = $DB->getColumn("SELECT v FROM pre_config WHERE k='kpay_sp_db' LIMIT 1");
		if ($version === self::DB_VERSION) {
			return;
		}
		if ($version === '1') {
			// v1 → v2：代商户收款要记渠道商户ID和授权状态
			foreach ([
				"ALTER TABLE `pre_kpay_apply` ADD COLUMN `sp_binding_id` int(11) unsigned DEFAULT NULL",
				"ALTER TABLE `pre_kpay_apply` ADD COLUMN `auth_status` varchar(20) DEFAULT NULL",
				"ALTER TABLE `pre_kpay_apply` ADD COLUMN `auth_checked_at` datetime DEFAULT NULL",
				"ALTER TABLE `pre_kpay_apply` ADD KEY `sp_binding_id` (`sp_binding_id`)",
			] as $sql) {
				try {
					$DB->exec($sql);
				} catch (Exception $e) {
					// 列已存在（上次升级做到一半）时忽略
				}
			}
			$DB->exec("REPLACE INTO pre_config SET k='kpay_sp_db', v=:v", [':v' => self::DB_VERSION]);
			return;
		}
		$DB->exec("CREATE TABLE IF NOT EXISTS `pre_kpay_apply` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`uid` int(11) NOT NULL,
			`application_no` varchar(64) DEFAULT NULL,
			`external_no` varchar(64) NOT NULL,
			`merchant_name` varchar(100) DEFAULT NULL,
			`status` varchar(40) NOT NULL DEFAULT 'draft',
			`status_label` varchar(60) DEFAULT NULL,
			`activation_status` varchar(24) DEFAULT NULL,
			`merchant_code` varchar(64) DEFAULT NULL,
			`review_remark` varchar(1000) DEFAULT NULL,
			`summary` text DEFAULT NULL,
			`bind_pid` varchar(32) DEFAULT NULL,
			`bound_at` datetime DEFAULT NULL,
			`sp_binding_id` int(11) unsigned DEFAULT NULL,
			`auth_status` varchar(20) DEFAULT NULL,
			`auth_checked_at` datetime DEFAULT NULL,
			`addtime` datetime DEFAULT NULL,
			`updatetime` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `external_no` (`external_no`),
			KEY `uid` (`uid`),
			KEY `application_no` (`application_no`),
			KEY `sp_binding_id` (`sp_binding_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$DB->exec("REPLACE INTO pre_config SET k='kpay_sp_db', v=:v", [':v' => self::DB_VERSION]);
	}

	public function latestForUser($uid)
	{
		global $DB;
		return $DB->getRow("SELECT * FROM pre_kpay_apply WHERE uid=:uid ORDER BY id DESC LIMIT 1", [':uid' => (int)$uid]);
	}

	public function getApply($id)
	{
		global $DB;
		return $DB->getRow("SELECT * FROM pre_kpay_apply WHERE id=:id LIMIT 1", [':id' => (int)$id]);
	}

	/**
	 * 退回、关闭的单子可以重新申请，其余状态继续用原单
	 */
	public static function canStartNew($row)
	{
		return !$row || in_array($row['status'], ['rejected', 'closed'], true);
	}

	// ─── 进件流程 ───────────────────────────────────────────────────

	/**
	 * 保存资料：没有进件单就创建，有就先取回 KPay 上的完整资料再覆盖本次修改，
	 * 避免把已上传的图片、OCR 结果清掉。
	 */
	public function saveDraft($uid, array $form, $startNew = false)
	{
		global $DB;
		if (!$this->configured()) {
			throw new Exception('进件功能尚未配置');
		}
		$row = $this->latestForUser($uid);
		$client = $this->client();

		if (!$row || !$row['application_no'] || ($startNew && self::canStartNew($row))) {
			$body = self::buildBody($form, [], $this->settings(), null);
			self::assertRequired($body);
			$externalNo = $row && !$row['application_no'] ? $row['external_no'] : self::newExternalNo($uid);
			if (!$row || $row['application_no']) {
				$DB->insert('kpay_apply', [
					'uid' => (int)$uid, 'external_no' => $externalNo, 'merchant_name' => mb_substr($body['merchantName'], 0, 100),
					'status' => 'draft', 'addtime' => 'NOW()', 'updatetime' => 'NOW()',
				]);
				$row = $DB->getRow("SELECT * FROM pre_kpay_apply WHERE external_no=:no LIMIT 1", [':no' => $externalNo]);
			}
			$body['externalRequestNo'] = $externalNo;
			$body['serviceMode'] = 'self_service';
			$callbackUrl = $this->callbackUrl();
			if ($callbackUrl !== '') {
				$body['callbackUrl'] = $callbackUrl;
				$body['callbackSecret'] = $this->setting('callback_secret');
			}
			$view = $client->api('POST', '/pay/api/onboarding/applications', [], $body);
			$applicationNo = isset($view['application']['applicationNo']) ? $view['application']['applicationNo'] : '';
			if ($applicationNo === '') {
				throw new Exception('KPay 未返回进件单号');
			}
			$DB->update('kpay_apply', ['application_no' => $applicationNo], ['id' => $row['id']]);
			$row['application_no'] = $applicationNo;
		} else {
			$remote = $this->remoteView($row['application_no']);
			if (empty($remote['diagnostics']['editable']) && isset($remote['diagnostics']['editable'])) {
				throw new Exception('当前进件单不能修改');
			}
			$body = self::buildBody($form, isset($remote['commonProfile']) ? $remote['commonProfile'] : [], $this->settings(), isset($remote['selectedProviders']) ? $remote['selectedProviders'] : null);
			self::assertRequired($body);
			$view = $client->api('PUT', '/pay/api/onboarding/applications/' . rawurlencode($row['application_no']), [], $body);
		}
		$this->storeView($row['id'], $view);
		return $this->getApply($row['id']);
	}

	public function uploadMaterial($row, $materialType, array $file)
	{
		if (!array_key_exists($materialType, self::MATERIAL_TYPES)) {
			throw new Exception('不支持的材料类型');
		}
		if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			throw new Exception('请选择要上传的图片');
		}
		if ($file['size'] > 5 * 1024 * 1024) {
			throw new Exception('图片不能超过 5MB');
		}
		$bytes = file_get_contents($file['tmp_name']);
		$mime = self::detectImageMime($bytes);
		if ($mime === '') {
			throw new Exception('只支持 jpg、png、gif、webp 图片');
		}
		$extension = explode('/', $mime)[1];
		$result = $this->client()->upload(
			'/pay/api/onboarding/applications/' . rawurlencode($row['application_no']) . '/materials',
			['materialType' => $materialType, 'actorMode' => 'service_provider'],
			'file',
			$materialType . '.' . ($extension === 'jpeg' ? 'jpg' : $extension),
			$mime,
			$bytes
		);
		if (isset($result['view']) && is_array($result['view'])) {
			$this->storeView($row['id'], $result['view']);
		}
		return [
			'ocrStatus'  => isset($result['ocrStatus']) ? $result['ocrStatus'] : '',
			'ocrMessage' => isset($result['ocrMessage']) ? $result['ocrMessage'] : '',
		];
	}

	public function resolveBankCard($row, array $form)
	{
		$body = ['actorMode' => 'service_provider'];
		foreach (['bankAccountNo', 'bankName', 'bankBranchName', 'provinceCode', 'cityCode', 'countyCode', 'settlementInterBankNo'] as $field) {
			if (isset($form[$field]) && trim($form[$field]) !== '') {
				$body[$field] = trim($form[$field]);
			}
		}
		return $this->client()->api('POST', '/pay/api/onboarding/applications/' . rawurlencode($row['application_no']) . '/bank-card-info', [], $body);
	}

	public function submit($row)
	{
		$view = $this->client()->api('POST', '/pay/api/onboarding/applications/' . rawurlencode($row['application_no']) . '/submit', [], ['actorMode' => 'service_provider']);
		$this->storeView($row['id'], $view);
		return $this->getApply($row['id']);
	}

	public function sync($row)
	{
		$view = $this->client()->api('POST', '/pay/api/onboarding/applications/' . rawurlencode($row['application_no']) . '/sync-status', [], ['actorMode' => 'service_provider']);
		$this->storeView($row['id'], $view);
		return $view;
	}

	public function remoteView($applicationNo)
	{
		return $this->client()->api('GET', '/pay/api/onboarding/applications/' . rawurlencode($applicationNo), ['actorMode' => 'service_provider']);
	}

	public function regions($provinceCode, $cityCode)
	{
		$providers = $this->setting('providers');
		$query = ['provider' => $providers ? $providers[0] : 'fuyou', 'actorMode' => 'service_provider'];
		if ($provinceCode !== '') {
			$query['provinceCode'] = $provinceCode;
		}
		if ($cityCode !== '') {
			$query['cityCode'] = $cityCode;
		}
		$data = $this->client()->api('GET', '/pay/api/onboarding/basic-options', $query);
		return [
			'province' => isset($data['provinceOptions']) ? $data['provinceOptions'] : [],
			'city'     => isset($data['cityOptions']) ? $data['cityOptions'] : [],
			'county'   => isset($data['countyOptions']) ? $data['countyOptions'] : [],
		];
	}

	/**
	 * KPay 推送的进件状态变化。回调只作通知，写入本地状态后管理员页面仍可主动同步
	 */
	public function applyCallback(array $payload)
	{
		global $DB;
		$row = $DB->getRow("SELECT * FROM pre_kpay_apply WHERE application_no=:no LIMIT 1", [':no' => (string)$payload['applicationNo']]);
		if (!$row) {
			return false;
		}
		$status = isset($payload['status']) ? (string)$payload['status'] : $row['status'];
		$data = [
			'status'            => $status,
			'status_label'      => self::statusLabel($status),
			'activation_status' => isset($payload['activationStatus']) ? (string)$payload['activationStatus'] : $row['activation_status'],
			'updatetime'        => 'NOW()',
		];
		if (!empty($payload['merchantCode'])) {
			$data['merchant_code'] = (string)$payload['merchantCode'];
		}
		if (isset($payload['reviewRemark'])) {
			$data['review_remark'] = mb_substr((string)$payload['reviewRemark'], 0, 1000);
		}
		$DB->update('kpay_apply', $data, ['id' => $row['id']]);
		return true;
	}

	public function storeView($id, array $view)
	{
		global $DB;
		$application = isset($view['application']) && is_array($view['application']) ? $view['application'] : [];
		$diagnostics = isset($view['diagnostics']) && is_array($view['diagnostics']) ? $view['diagnostics'] : [];
		$status = isset($application['status']) ? (string)$application['status'] : 'draft';
		$data = [
			'status'       => $status,
			'status_label' => !empty($diagnostics['statusLabel']) ? mb_substr($diagnostics['statusLabel'], 0, 60) : self::statusLabel($status),
			'summary'      => json_encode(self::summarize($view), JSON_UNESCAPED_UNICODE),
			'updatetime'   => 'NOW()',
		];
		if (isset($application['activationStatus'])) {
			$data['activation_status'] = (string)$application['activationStatus'];
		}
		if (!empty($application['merchantCode'])) {
			$data['merchant_code'] = (string)$application['merchantCode'];
		}
		if (isset($application['reviewRemark'])) {
			$data['review_remark'] = mb_substr((string)$application['reviewRemark'], 0, 1000);
		}
		if (!empty($view['commonProfile']['merchantName'])) {
			$data['merchant_name'] = mb_substr($view['commonProfile']['merchantName'], 0, 100);
		}
		$DB->update('kpay_apply', $data, ['id' => (int)$id]);
	}

	/**
	 * 商户开通 KPay 账户后，用他的商户ID和密钥生成易支付子通道
	 */
	public function bind($row, $pid, $key)
	{
		global $DB;
		$pid = trim((string)$pid);
		$key = trim((string)$key);
		if (!preg_match('/^\d{1,20}$/', $pid) || $key === '') {
			throw new Exception('请填写正确的商户ID和密钥');
		}
		$channels = $this->setting('bind_channels');
		if (!$channels) {
			throw new Exception('管理员还没有设置用于绑定的 KPay 通道');
		}
		$result = $this->epayClient()->epayQueryMerchant($pid, $key);
		if (!isset($result['code']) || (int)$result['code'] !== 1) {
			throw new Exception('商户ID或密钥不正确');
		}

		$count = $this->upsertSubchannels((int)$row['uid'], ['appid' => $pid, 'appkey' => $key], 'KPay ' . $pid, 1);
		$DB->update('kpay_apply', [
			'bind_pid' => $pid, 'bound_at' => 'NOW()', 'sp_binding_id' => null, 'auth_status' => null,
		], ['id' => $row['id']]);
		return $count;
	}

	/**
	 * 给商户在每个绑定通道下生成或更新子通道，返回处理的通道数
	 */
	private function upsertSubchannels($uid, array $info, $name, $status)
	{
		global $DB;
		$channels = $this->setting('bind_channels');
		if (!$channels) {
			throw new Exception('管理员还没有设置用于绑定的 KPay 通道');
		}
		$encoded = json_encode($info);
		$count = 0;
		foreach ($channels as $channelId) {
			$channel = $DB->getRow("SELECT id,plugin FROM pre_channel WHERE id=:id LIMIT 1", [':id' => $channelId]);
			if (!$channel || $channel['plugin'] !== 'kpay') {
				continue;
			}
			$existing = $DB->getRow("SELECT id FROM pre_subchannel WHERE channel=:c AND uid=:u LIMIT 1", [':c' => $channelId, ':u' => (int)$uid]);
			if ($existing) {
				$DB->update('subchannel', ['info' => $encoded, 'status' => $status, 'name' => $name], ['id' => $existing['id']]);
			} else {
				$DB->insert('subchannel', [
					'channel' => $channelId, 'uid' => (int)$uid, 'name' => $name,
					'status' => $status, 'info' => $encoded, 'addtime' => 'NOW()',
				]);
			}
			$count++;
		}
		if ($count === 0) {
			throw new Exception('没有可用的 KPay 通道');
		}
		return $count;
	}

	// ─── 服务商代收（商户授权后用服务商密钥代下单） ─────────────────

	/**
	 * 子通道商户ID是 sp:<渠道商户ID> 时返回渠道商户ID，否则返回 0
	 */
	public static function delegatedBindingId($appid)
	{
		$appid = trim((string)$appid);
		if (strpos($appid, self::DELEGATE_PREFIX) !== 0) {
			return 0;
		}
		$id = substr($appid, strlen(self::DELEGATE_PREFIX));
		return ctype_digit($id) && (int)$id > 0 ? (int)$id : 0;
	}

	public static function subMerchantHeader($bindingId)
	{
		return 'X-KPay-Sub-Merchant: ' . (int)$bindingId;
	}

	public static function authLabel($status)
	{
		$labels = self::AUTH_LABELS;
		return isset($labels[$status]) ? $labels[$status] : (string)$status;
	}

	/**
	 * 授权记录里有代收款范围且已生效才算 active，其余原样返回
	 */
	public static function paymentAuthStatus($authorization)
	{
		if (!is_array($authorization) || empty($authorization['status'])) {
			return 'none';
		}
		$status = (string)$authorization['status'];
		$scopes = isset($authorization['scopes']) && is_array($authorization['scopes']) ? $authorization['scopes'] : [];
		if ($status === 'active' && !in_array('payment', $scopes, true)) {
			return 'none';
		}
		return $status;
	}

	/**
	 * 进件通过、商户开通后，按平台商户号在旗下商户里找到对应的渠道商户ID
	 */
	public function findBindingId($row)
	{
		$code = trim((string)$row['merchant_code']);
		if ($code === '') {
			return 0;
		}
		$data = $this->client()->api('GET', '/pay/api/service-provider/merchants', ['keyword' => $code, 'pageSize' => 20]);
		foreach (isset($data['list']) ? $data['list'] : [] as $item) {
			if (isset($item['merchantCode'], $item['id']) && (string)$item['merchantCode'] === $code) {
				return (int)$item['id'];
			}
		}
		return 0;
	}

	/**
	 * 服务商代收：确认渠道商户可用，没有授权就向商户发起申请，然后生成子通道。
	 * 商户同意前子通道是停用的，同意后（事件推送或刷新时）自动启用。
	 *
	 * @param int $bindingId 0 表示按平台商户号自动查找
	 * @return string 授权状态
	 */
	public function bindDelegated($row, $bindingId = 0)
	{
		global $DB, $conf;
		$bindingId = (int)$bindingId;
		if ($bindingId <= 0) {
			$bindingId = $this->findBindingId($row);
		}
		if ($bindingId <= 0) {
			throw new Exception('还没找到这个商户的 KPay 账户：请确认商户已完成开通；也可以在「旗下商户」查到渠道商户ID后手动填写');
		}
		$other = $DB->getRow("SELECT id,uid FROM pre_kpay_apply WHERE sp_binding_id=:b LIMIT 1", [':b' => $bindingId]);
		if ($other && (int)$other['uid'] !== (int)$row['uid']) {
			throw new Exception('这个渠道商户已经绑定给 UID ' . (int)$other['uid'] . '，不能重复绑定');
		}

		$merchant = $this->client()->api('GET', '/pay/api/service-provider/merchants/' . $bindingId);
		if (empty($merchant['merchantId'])) {
			throw new Exception('这个商户还没有开通收款，开通后再绑定');
		}
		if (isset($merchant['status']) && $merchant['status'] !== 'active') {
			throw new Exception('这个渠道商户已停用');
		}
		$status = self::paymentAuthStatus(isset($merchant['authorization']) ? $merchant['authorization'] : null);
		if ($status !== 'active' && $status !== 'pending') {
			$siteName = !empty($conf['sitename']) ? $conf['sitename'] : '本站';
			$created = $this->client()->api('POST', '/pay/api/service-provider/merchants/' . $bindingId . '/authorization', [], [
				'scopes' => ['payment'],
				'note'   => mb_substr('授权「' . $siteName . '」代您在本站收款，款项直接结算到您的账户', 0, 200),
			]);
			$status = isset($created['status']) ? (string)$created['status'] : 'pending';
		}

		$this->upsertSubchannels((int)$row['uid'], ['appid' => self::DELEGATE_PREFIX . $bindingId, 'appkey' => self::DELEGATE_KEY],
			'KPay 代收 ' . (!empty($merchant['merchantName']) ? mb_substr($merchant['merchantName'], 0, 20) : $bindingId), $status === 'active' ? 1 : 0);
		$DB->update('kpay_apply', [
			'bind_pid' => self::DELEGATE_PREFIX . $bindingId, 'bound_at' => 'NOW()',
			'sp_binding_id' => $bindingId, 'auth_status' => $status, 'auth_checked_at' => 'NOW()',
		], ['id' => $row['id']]);
		return $status;
	}

	/**
	 * 向 KPay 查询授权状态并同步到本地和子通道
	 */
	public function refreshAuthorization($row)
	{
		$bindingId = (int)$row['sp_binding_id'];
		if ($bindingId <= 0) {
			return null;
		}
		$merchant = $this->client()->api('GET', '/pay/api/service-provider/merchants/' . $bindingId);
		$status = self::paymentAuthStatus(isset($merchant['authorization']) ? $merchant['authorization'] : null);
		if (isset($merchant['status']) && $merchant['status'] !== 'active') {
			$status = 'revoked';
		}
		$this->applyAuthorizationStatus($bindingId, $status);
		return $status;
	}

	/**
	 * 授权变化：已授权时启用代收子通道，其余状态停用
	 */
	public function applyAuthorizationStatus($bindingId, $status)
	{
		global $DB;
		$bindingId = (int)$bindingId;
		$rows = $DB->getAll("SELECT id,uid FROM pre_kpay_apply WHERE sp_binding_id=:b", [':b' => $bindingId]);
		foreach ($rows as $row) {
			$DB->update('kpay_apply', ['auth_status' => (string)$status, 'auth_checked_at' => 'NOW()'], ['id' => $row['id']]);
			foreach ($this->setting('bind_channels') as $channelId) {
				$sub = $DB->getRow("SELECT id,info FROM pre_subchannel WHERE channel=:c AND uid=:u LIMIT 1", [':c' => $channelId, ':u' => (int)$row['uid']]);
				$info = $sub && $sub['info'] ? json_decode($sub['info'], true) : null;
				if (is_array($info) && isset($info['appid']) && self::delegatedBindingId($info['appid']) === $bindingId) {
					$DB->update('subchannel', ['status' => $status === 'active' ? 1 : 0], ['id' => $sub['id']]);
				}
			}
		}
		return count($rows);
	}

	/**
	 * 在「旗下商户」里直接申请或撤回代收款授权
	 */
	public function requestAuthorization($bindingId)
	{
		global $conf;
		$siteName = !empty($conf['sitename']) ? $conf['sitename'] : '本站';
		$result = $this->client()->api('POST', '/pay/api/service-provider/merchants/' . (int)$bindingId . '/authorization', [], [
			'scopes' => ['payment'],
			'note'   => mb_substr('授权「' . $siteName . '」代您在本站收款，款项直接结算到您的账户', 0, 200),
		]);
		$this->applyAuthorizationStatus($bindingId, isset($result['status']) ? $result['status'] : 'pending');
		return $result;
	}

	public function cancelAuthorization($bindingId)
	{
		$result = $this->client()->api('DELETE', '/pay/api/service-provider/merchants/' . (int)$bindingId . '/authorization');
		$this->applyAuthorizationStatus($bindingId, isset($result['status']) ? $result['status'] : 'revoked');
		return $result;
	}

	// ─── 事件推送 ───────────────────────────────────────────────────

	/**
	 * 把服务商的事件推送地址设为本站。KPay 一个服务商只有一个推送地址，已经指向别处时
	 * 需要 $force 确认才覆盖。
	 *
	 * @return array ['configured' => bool, 'url' => string, 'currentUrl' => string]
	 */
	public function enableWebhook($force = false)
	{
		$url = $this->webhookUrl();
		if ($url === '') {
			throw new Exception('请先在设置里选择回调通道并保存');
		}
		$client = $this->client();
		$current = $client->api('GET', '/pay/api/service-provider/webhook');
		$currentUrl = !empty($current['configured']) && isset($current['url']) ? (string)$current['url'] : '';
		if ($currentUrl !== '' && $currentUrl !== $url && !$force) {
			return ['configured' => false, 'url' => $url, 'currentUrl' => $currentUrl];
		}
		$saved = $client->api('PUT', '/pay/api/service-provider/webhook', [], [
			'url' => $url, 'events' => self::WEBHOOK_EVENTS, 'status' => 'active',
		]);
		$secret = isset($saved['secret']) ? (string)$saved['secret'] : '';
		if ($secret === '' && ($this->setting('webhook_secret') === '' || $currentUrl !== $url)) {
			$rotated = $client->api('POST', '/pay/api/service-provider/webhook/rotate-secret');
			$secret = isset($rotated['secret']) ? (string)$rotated['secret'] : '';
			if ($secret === '') {
				throw new Exception('KPay 没有返回推送签名密钥');
			}
		}
		$updates = ['webhook_url' => $url];
		if ($secret !== '') {
			$updates['webhook_secret'] = $secret;
		}
		$this->updateSettings($updates);
		return ['configured' => true, 'url' => $url, 'currentUrl' => $url];
	}

	public function disableWebhook()
	{
		$client = $this->client();
		$current = $client->api('GET', '/pay/api/service-provider/webhook');
		if (!empty($current['configured']) && isset($current['url']) && $current['url'] === $this->setting('webhook_url')) {
			$client->api('DELETE', '/pay/api/service-provider/webhook');
		}
		$this->updateSettings(['webhook_url' => '', 'webhook_secret' => '']);
	}

	public function webhookStatus()
	{
		$view = $this->client()->api('GET', '/pay/api/service-provider/webhook');
		$ours = !empty($view['configured']) && isset($view['url']) && $view['url'] === $this->setting('webhook_url') && $this->setting('webhook_secret') !== '';
		return [
			'enabled'         => $ours,
			'url'             => isset($view['url']) ? (string)$view['url'] : '',
			'expectedUrl'     => $this->webhookUrl(),
			'status'          => isset($view['status']) ? (string)$view['status'] : '',
			'lastDeliveredAt' => isset($view['lastDeliveredAt']) ? $view['lastDeliveredAt'] : null,
			'lastFailedAt'    => isset($view['lastFailedAt']) ? $view['lastFailedAt'] : null,
			'lastError'       => isset($view['lastError']) ? (string)$view['lastError'] : '',
		];
	}

	public function testWebhook()
	{
		return $this->client()->api('POST', '/pay/api/service-provider/webhook/test');
	}

	/**
	 * 处理一条已验签的推送事件。推送只是通知，同一事件重复到达时结果一样
	 */
	public function handleEvent(array $event)
	{
		$type = isset($event['type']) ? (string)$event['type'] : '';
		$data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];
		if ($type === 'onboarding.status_changed' && !empty($data['applicationNo'])) {
			return $this->applyCallback($data);
		}
		if ($type === 'merchant.authorization_changed' && !empty($data['bindingId'])) {
			$status = self::paymentAuthStatus($data);
			$this->applyAuthorizationStatus((int)$data['bindingId'], $status);
			return true;
		}
		return false;
	}

	private function updateSettings(array $updates)
	{
		global $DB;
		$next = array_merge($this->settings(), $updates);
		$DB->exec("REPLACE INTO pre_config SET k=:k, v=:v", [':k' => self::SETTING_KEY, ':v' => json_encode($next, JSON_UNESCAPED_UNICODE)]);
		$this->settings = $next;
	}

	/**
	 * 下级商户的 EPay 密钥要把本站域名设为授权域名，并通过域名归属验证。
	 * 验证文件每一行都算数，所以多个商户的验证码可以追加在同一个文件里。
	 *
	 * @return bool 新写入返回 true，已存在返回 false
	 */
	public function addDomainVerification($line, $root = null)
	{
		$line = self::normalizeVerificationLine($line);
		$root = $root !== null ? $root : (defined('ROOT') ? ROOT : '');
		if ($root === '') {
			throw new Exception('无法确定网站根目录');
		}
		$path = rtrim($root, '/\\') . '/' . self::DOMAIN_VERIFY_FILE;
		$lines = [];
		if (is_file($path)) {
			$lines = array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES)), 'strlen'));
		}
		if (in_array($line, $lines, true)) {
			return false;
		}
		$lines[] = $line;
		if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
			throw new Exception('写入验证文件失败，请检查网站根目录是否可写，或手动把这一行加到 ' . self::DOMAIN_VERIFY_FILE);
		}
		return true;
	}

	public static function normalizeVerificationLine($line)
	{
		$line = trim((string)$line);
		if (!preg_match('/^kpay-domain-verification=[A-Za-z0-9_-]{16,64}$/D', $line)) {
			throw new Exception('验证码格式不对，应为 kpay-domain-verification= 开头的一整行');
		}
		return $line;
	}

	// ─── 服务商查询 ─────────────────────────────────────────────────

	public function serviceProvider($resource, array $query)
	{
		$allowed = ['overview', 'merchants', 'commissions', 'rate-changes', 'complaints', 'orders', 'refunds', 'settlements', 'opened-merchants', 'onboarding'];
		if (!in_array($resource, $allowed, true)) {
			throw new Exception('不支持的查询');
		}
		$clean = [];
		foreach (['page', 'pageSize', 'status', 'activity', 'type', 'keyword', 'orderNo', 'startTime', 'endTime', 'startDate', 'endDate', 'bindingId', 'provider', 'group'] as $field) {
			if (isset($query[$field]) && is_scalar($query[$field]) && trim((string)$query[$field]) !== '') {
				$clean[$field] = trim((string)$query[$field]);
			}
		}
		return $this->client()->api('GET', '/pay/api/service-provider/' . $resource, $clean);
	}

	/**
	 * 下载某天（北京时间）的对账单 CSV
	 */
	public function dailyStatement($date)
	{
		$date = trim((string)$date);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
			throw new Exception('日期格式应为 YYYY-MM-DD');
		}
		return $this->client()->apiRaw('GET', '/pay/api/service-provider/statements/daily', ['date' => $date]);
	}

	public function submitRateChange(array $input)
	{
		$body = [
			'bindingId'     => (int)(isset($input['bindingId']) ? $input['bindingId'] : 0),
			'provider'      => trim((string)(isset($input['provider']) ? $input['provider'] : '')),
			'payMethod'     => trim((string)(isset($input['payMethod']) ? $input['payMethod'] : '')),
			'targetFeeRate' => round((float)(isset($input['targetFeeRate']) ? $input['targetFeeRate'] : 0), 6),
			'reason'        => mb_substr(trim((string)(isset($input['reason']) ? $input['reason'] : '')), 0, 200),
		];
		if ($body['bindingId'] <= 0 || $body['provider'] === '' || !in_array($body['payMethod'], ['wechat', 'alipay'], true) || $body['targetFeeRate'] <= 0 || $body['reason'] === '') {
			throw new Exception('请完整填写费率调整信息');
		}
		return $this->client()->api('POST', '/pay/api/service-provider/rate-changes', [], $body);
	}

	public function cancelRateChange($id)
	{
		return $this->client()->api('DELETE', '/pay/api/service-provider/rate-changes/' . (int)$id);
	}

	public function options()
	{
		return $this->client()->api('GET', '/pay/api/onboarding/options', ['actorMode' => 'service_provider']);
	}

	// ─── 纯函数（便于测试） ─────────────────────────────────────────

	/**
	 * 组装建单 / 改单请求体
	 *
	 * @param array $form 本次提交的表单
	 * @param array $remoteProfile KPay 上现有的 commonProfile，作为底稿保留图片和 OCR 字段
	 * @param array $settings 模块设置
	 * @param array|null $remoteProviders 已有进件单的机构，改单时沿用
	 */
	public static function buildBody(array $form, array $remoteProfile, array $settings, $remoteProviders)
	{
		$body = $remoteProfile;
		foreach (self::FORM_FIELDS as $field) {
			if (array_key_exists($field, $form) && is_scalar($form[$field])) {
				$body[$field] = trim((string)$form[$field]);
			}
		}

		$extra = !empty($settings['extra_create']) ? json_decode($settings['extra_create'], true) : [];
		if (is_array($extra)) {
			foreach ($extra as $key => $value) {
				if (!in_array($key, self::FORM_FIELDS, true)) {
					$body[$key] = $value;
				}
			}
		}

		$providers = is_array($remoteProviders) && $remoteProviders ? array_values($remoteProviders) : array_values($settings['providers']);
		$body['selectedProviders'] = $providers;
		if (!empty($settings['package_code'])) {
			$body['onboardingPackageCode'] = $settings['package_code'];
		}
		if (in_array('alipay', $providers, true) && !empty($body['industryCodePreferred'])) {
			$profiles = isset($body['providerIndustryProfiles']) && is_array($body['providerIndustryProfiles']) ? $body['providerIndustryProfiles'] : [];
			$alipay = isset($profiles['alipay']) && is_array($profiles['alipay']) ? $profiles['alipay'] : [];
			if (empty($alipay['industryCodePreferred'])) {
				$alipay['industryCodePreferred'] = $body['industryCodePreferred'];
			}
			$profiles['alipay'] = $alipay;
			$body['providerIndustryProfiles'] = $profiles;
		}
		$body['actorMode'] = 'service_provider';
		return $body;
	}

	public static function assertRequired(array $body)
	{
		$labels = [
			'merchantName' => '商户名称', 'subjectType' => '主体类型', 'contactMobile' => '联系人手机号',
			'industryKeyword' => '经营类目', 'provinceCode' => '经营省份', 'cityCode' => '经营城市',
			'countyCode' => '经营区县', 'addressDetail' => '详细地址',
		];
		foreach ($labels as $field => $label) {
			if (empty($body[$field])) {
				throw new Exception('请填写' . $label);
			}
		}
		if (!in_array($body['subjectType'], ['personal', 'individual_business', 'enterprise'], true)) {
			throw new Exception('主体类型不正确');
		}
		if (!preg_match('/^1\d{10}$/', $body['contactMobile'])) {
			throw new Exception('联系人手机号格式不正确');
		}
		if (empty($body['selectedProviders'])) {
			throw new Exception('管理员还没有选择进件机构');
		}
	}

	/**
	 * 本地只留进度摘要，不存证件和银行卡
	 */
	public static function summarize(array $view)
	{
		$diagnostics = isset($view['diagnostics']) && is_array($view['diagnostics']) ? $view['diagnostics'] : [];
		$summary = [];
		foreach (['canSubmit', 'editable', 'progressPercent', 'missingFields', 'blockingReasons', 'nextActions'] as $field) {
			if (isset($diagnostics[$field])) {
				$summary[$field] = $diagnostics[$field];
			}
		}
		$summary['missingMaterials'] = [];
		if (!empty($diagnostics['missingMaterials']) && is_array($diagnostics['missingMaterials'])) {
			foreach ($diagnostics['missingMaterials'] as $item) {
				if (is_array($item) && !empty($item['materialType'])) {
					$summary['missingMaterials'][] = ['materialType' => $item['materialType'], 'label' => isset($item['label']) ? $item['label'] : $item['materialType']];
				}
			}
		}
		return $summary;
	}

	/**
	 * 给商户看的视图：只有状态、缺什么、下一步和自己填过的资料
	 */
	public static function merchantView(array $view)
	{
		$application = isset($view['application']) && is_array($view['application']) ? $view['application'] : [];
		$profile = isset($view['commonProfile']) && is_array($view['commonProfile']) ? $view['commonProfile'] : [];
		$status = isset($application['status']) ? (string)$application['status'] : 'draft';

		$form = [];
		foreach (self::FORM_FIELDS as $field) {
			if (isset($profile[$field]) && is_scalar($profile[$field])) {
				$form[$field] = (string)$profile[$field];
			}
		}

		$uploaded = [];
		if (!empty($view['materials']) && is_array($view['materials'])) {
			foreach ($view['materials'] as $material) {
				if (is_array($material) && !empty($material['materialType'])) {
					$uploaded[$material['materialType']] = true;
				}
			}
		}

		$confirmUrls = [];
		$statuses = isset($view['providerStatuses']) && is_array($view['providerStatuses']) ? $view['providerStatuses'] : [];
		foreach ($statuses as $item) {
			if (is_array($item) && !empty($item['remoteSignUrl']) && preg_match('#^https://#i', $item['remoteSignUrl'])) {
				$confirmUrls[] = $item['remoteSignUrl'];
			}
		}

		$summary = self::summarize($view);
		$diagnostics = isset($view['diagnostics']) && is_array($view['diagnostics']) ? $view['diagnostics'] : [];
		return [
			'status'           => $status,
			'statusLabel'      => !empty($diagnostics['statusLabel']) ? $diagnostics['statusLabel'] : self::statusLabel($status),
			'editable'         => isset($summary['editable']) ? (bool)$summary['editable'] : $status === 'draft',
			'canSubmit'        => !empty($summary['canSubmit']),
			'progressPercent'  => isset($summary['progressPercent']) ? (int)$summary['progressPercent'] : 0,
			'missingFields'    => isset($summary['missingFields']) ? $summary['missingFields'] : [],
			'missingMaterials' => $summary['missingMaterials'],
			'nextActions'      => isset($summary['nextActions']) ? $summary['nextActions'] : [],
			'reviewRemark'     => isset($application['reviewRemark']) ? (string)$application['reviewRemark'] : '',
			'uploaded'         => array_keys($uploaded),
			'confirmUrls'      => array_values(array_unique($confirmUrls)),
			'form'             => $form,
		];
	}

	public static function statusLabel($status)
	{
		$labels = self::STATUS_LABELS;
		return isset($labels[$status]) ? $labels[$status] : (string)$status;
	}

	public static function newExternalNo($uid)
	{
		return 'EPAY' . (int)$uid . 'T' . date('YmdHis') . bin2hex(random_bytes(3));
	}

	public static function detectImageMime($bytes)
	{
		$head = substr((string)$bytes, 0, 12);
		if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
			return 'image/jpeg';
		}
		if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
			return 'image/png';
		}
		if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
			return 'image/gif';
		}
		if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
			return 'image/webp';
		}
		return '';
	}

	private static function cleanList($value, $pattern)
	{
		if (is_string($value)) {
			$value = preg_split('/[\s,]+/', $value);
		}
		if (!is_array($value)) {
			return [];
		}
		$out = [];
		foreach ($value as $item) {
			$item = trim((string)$item);
			if ($item !== '' && preg_match($pattern, $item) && !in_array($item, $out, true)) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
