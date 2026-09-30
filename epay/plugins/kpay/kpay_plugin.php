<?php

/**
 * 彩虹易支付 × KPay 通道插件
 *
 * 把 KPay 接成易支付的上游通道。总通道填你自己的 KPay 商户；服务商模式下，
 * 给每个下级商户开「子通道」，填各自在 KPay 的商户ID和密钥，钱直接结算到对应商户。
 * 配合 admin/kpay.php、user/kpay.php 还能在易支付里给下级商户提交进件。
 *
 * 目录名必须是 kpay，放到易支付根目录 plugins/ 下。
 */
require_once __DIR__ . '/inc/KpayClient.php';

class kpay_plugin
{
	static public $info = [
		'name'     => 'kpay',
		'showname' => 'KPay 凯付',
		'author'   => 'KPay',
		'link'     => 'https://github.com/kpayys/kpay-plugins',
		'types'    => ['alipay', 'wxpay', 'bank'],
		'inputs'   => [
			'appurl' => [
				'name' => '接口地址',
				'type' => 'input',
				'note' => '填 https://api.kaipay.cn/ ，以 / 结尾',
			],
			'appid' => [
				'name' => '商户ID',
				'type' => 'input',
				'note' => 'KPay 后台「EPay 配置」里的商户ID（纯数字）；服务商主通道填 [appid]',
			],
			'appkey' => [
				'name' => '商户密钥',
				'type' => 'input',
				'note' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key；服务商主通道填 [appkey]',
			],
			'appswitch' => [
				'name' => '下单方式',
				'type' => 'select',
				'options' => [0 => '跳转 KPay 付款页', 1 => '服务端下单（失败时能看到原因）'],
			],
			'appmchid' => [
				'name' => '退款 API Key',
				'type' => 'input',
				'note' => '选填。KPay「API 密钥」里创建的平台 API 密钥（勾选「发起退款」权限），不填则不支持在易支付后台退款',
			],
			'appsecret' => [
				'name' => '退款 API Secret',
				'type' => 'input',
				'note' => '选填。与上面的 API Key 配对的 Secret',
			],
		],
		'select'   => null,
		'note'     => '商户ID在 KPay 商户后台「EPay 接入 → EPay 配置」查看；EPay Key 在「API 密钥」页创建 EPay 兼容密钥时显示。服务商给下级商户开子通道时，填下级商户自己的商户ID和 EPay Key。',
		'bindwxmp' => false,
		'bindwxa'  => false,
	];

	static public function submit()
	{
		global $siteurl, $channel, $order;

		if (!empty($channel['appswitch'])) {
			return ['type' => 'jump', 'url' => $siteurl . 'pay/' . $order['typename'] . '/' . TRADE_NO . '/'];
		}

		try {
			$params = self::_orderParams(self::_payType($order['typename']));
			$action = self::_client()->gateway() . 'epay/submit';
		} catch (Exception $e) {
			return ['type' => 'error', 'msg' => $e->getMessage()];
		}

		return ['type' => 'html', 'data' => self::_autoSubmitForm($action, $params)];
	}

	// mapi.php 调用方要拿付款链接，不管通道配置哪种下单方式，都走服务端下单
	static public function mapi()
	{
		global $order;

		$typename = $order['typename'];
		if (!in_array($typename, self::$info['types'], true)) {
			return ['type' => 'error', 'msg' => '当前通道不支持该支付方式'];
		}
		return self::$typename();
	}

	static public function alipay()
	{
		return self::_payPage('alipay', 'alipay_qrcode');
	}

	static public function wxpay()
	{
		return self::_payPage('wxpay', 'wxpay_qrcode');
	}

	static public function bank()
	{
		return self::_payPage('unionpay', 'bank_qrcode');
	}

	static public function notify()
	{
		global $channel, $order;

		$data = $_GET;
		if (!KpayClient::epayVerify($data, $channel['appid'], $channel['appkey'])) {
			return ['type' => 'html', 'data' => 'fail'];
		}
		if (!isset($data['trade_status']) || $data['trade_status'] !== 'TRADE_SUCCESS') {
			return ['type' => 'html', 'data' => 'fail'];
		}
		if (!self::_matchesOrder($data, $order)) {
			return ['type' => 'html', 'data' => 'fail'];
		}

		processNotify($order, (string)$data['trade_no']);
		return ['type' => 'html', 'data' => 'success'];
	}

	static public function return()
	{
		global $channel, $order;

		$data = $_GET;
		if (!KpayClient::epayVerify($data, $channel['appid'], $channel['appkey'])) {
			return ['type' => 'error', 'msg' => '支付结果验签失败'];
		}
		if (!isset($data['trade_status']) || $data['trade_status'] !== 'TRADE_SUCCESS') {
			return ['type' => 'error', 'msg' => '订单尚未支付完成'];
		}
		if (!self::_matchesOrder($data, $order)) {
			return ['type' => 'error', 'msg' => '订单信息校验失败'];
		}

		processReturn($order, (string)$data['trade_no']);
	}

	static public function refund($order)
	{
		global $channel;
		if (empty($order)) {
			exit();
		}

		$apiKey = isset($channel['appmchid']) ? trim((string)$channel['appmchid']) : '';
		$apiSecret = isset($channel['appsecret']) ? trim((string)$channel['appsecret']) : '';
		if ($apiKey === '' || $apiSecret === '') {
			return ['code' => -1, 'msg' => '该通道未配置退款 API Key，请到 KPay 商户后台操作退款'];
		}
		if (empty($order['api_trade_no'])) {
			return ['code' => -1, 'msg' => '缺少 KPay 平台订单号，无法退款'];
		}

		$refundNo = !empty($order['refund_no']) ? $order['refund_no'] : 'R' . $order['trade_no'];
		try {
			$client = new KpayClient(isset($channel['appurl']) ? $channel['appurl'] : '', $apiKey, $apiSecret);
			$client->api('POST', '/pay/api/order/refund', [], [
				'orderNo'         => (string)$order['api_trade_no'],
				'refundAmount'    => round((float)$order['refundmoney'], 2),
				'refundRequestNo' => (string)$refundNo,
				'reason'          => '易支付后台退款',
			]);
		} catch (Exception $e) {
			return ['code' => -1, 'msg' => $e->getMessage()];
		}
		return ['code' => 0];
	}

	/**
	 * 进件状态回调：https://你的易支付/pay/applynotify/<KPay 通道ID>/
	 * 由 admin/kpay.php 创建进件单时自动填入，验签密钥保存在进件模块设置里。
	 */
	static public function applynotify()
	{
		$raw = file_get_contents('php://input');
		require_once __DIR__ . '/inc/KpayService.php';

		$service = KpayService::instance();
		if (!KpayClient::verifyCallback($service->setting('callback_secret'), KpayClient::requestHeaders(), $raw)) {
			return ['type' => 'html', 'data' => 'sign error'];
		}
		$payload = json_decode($raw, true);
		if (!is_array($payload) || empty($payload['applicationNo'])) {
			return ['type' => 'html', 'data' => 'bad payload'];
		}
		$service->applyCallback($payload);
		return ['type' => 'html', 'data' => 'success'];
	}

	// ─── 以下为内部方法（名字带下划线，易支付的 /pay/<方法>/ 路由匹配不到）───

	static private function _client()
	{
		global $channel;
		return new KpayClient(isset($channel['appurl']) ? $channel['appurl'] : '');
	}

	static private function _payPage($type, $qrcodePage)
	{
		try {
			list($method, $url) = self::_createOrder($type);
		} catch (Exception $e) {
			return ['type' => 'error', 'msg' => $e->getMessage()];
		}

		if ($method === 'jump') {
			return ['type' => 'jump', 'url' => $url];
		}
		return ['type' => 'qrcode', 'page' => $qrcodePage, 'url' => $url];
	}

	/**
	 * 调 KPay mapi 下单。同一笔订单重复进入（刷新页面、mapi 重试）时复用第一次的结果，
	 * 不再向 KPay 重复下单。
	 */
	static private function _createOrder($type)
	{
		$create = function () use ($type) {
			$params = self::_orderParams($type);
			$result = self::_client()->epayPost('epay/mapi', $params);

			if (!isset($result['code']) || (int)$result['code'] !== 1) {
				throw new Exception(!empty($result['msg']) ? $result['msg'] : 'KPay 下单失败');
			}
			if (!empty($result['payurl']) && preg_match('#^https?://#i', $result['payurl'])) {
				return ['jump', $result['payurl']];
			}
			if (!empty($result['qrcode'])) {
				return ['qrcode', $result['qrcode']];
			}
			throw new Exception('KPay 未返回付款地址');
		};

		if (class_exists('\\lib\\Payment') && method_exists('\\lib\\Payment', 'lockPayData')) {
			return \lib\Payment::lockPayData(TRADE_NO, $create);
		}
		return $create();
	}

	static private function _orderParams($type)
	{
		global $siteurl, $channel, $order, $ordername, $conf;

		$pid = trim((string)$channel['appid']);
		$key = trim((string)$channel['appkey']);
		if ($pid === '' || $key === '' || $pid[0] === '[') {
			throw new Exception('KPay 商户ID或商户密钥未配置');
		}

		$notifyBase = !empty($conf['localurl']) ? $conf['localurl'] : $siteurl;

		// 只传 KPay 用得上的字段；KPay 不会回传 param，也用不到 clientip
		$params = [
			'pid'          => $pid,
			'out_trade_no' => TRADE_NO,
			'notify_url'   => $notifyBase . 'pay/notify/' . TRADE_NO . '/',
			'return_url'   => $siteurl . 'pay/return/' . TRADE_NO . '/',
			'name'         => !empty($ordername) ? $ordername : $order['name'],
			'money'        => sprintf('%.2f', $order['realmoney']),
			'device'       => self::_device(),
		];
		if ($type !== '') {
			$params['type'] = $type;
		}
		$params['sign'] = KpayClient::epaySign($params, $key);
		$params['sign_type'] = 'MD5';
		return $params;
	}

	static private function _payType($typename)
	{
		switch ($typename) {
			case 'alipay':
				return 'alipay';
			case 'wxpay':
				return 'wxpay';
			case 'bank':
				return 'unionpay';
			default:
				return '';
		}
	}

	// mapi 调用方会传 device；页面下单时按浏览器判断
	static private function _device()
	{
		global $device;

		$value = isset($device) ? strtolower(trim((string)$device)) : '';
		if ($value === 'pc') {
			return 'pc';
		}
		if ($value !== '') {
			return 'mobile';
		}
		if (function_exists('checkmobile') && checkmobile()) {
			return 'mobile';
		}
		return 'pc';
	}

	// KPay 回调里的 money 是下单时传的金额，和 realmoney 对得上才入账
	static private function _matchesOrder(array $data, $order)
	{
		if (!isset($data['out_trade_no'], $data['money'], $data['trade_no'])) {
			return false;
		}
		if ((string)$data['out_trade_no'] !== (string)TRADE_NO) {
			return false;
		}
		return round((float)$data['money'], 2) == round((float)$order['realmoney'], 2);
	}

	static private function _autoSubmitForm($action, array $params)
	{
		$html = '<form id="kpaysubmit" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" method="post">';
		foreach ($params as $name => $value) {
			$html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
				. '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"/>';
		}
		$html .= '<input type="submit" value="正在跳转"></form>';
		$html .= '<script>document.getElementById("kpaysubmit").submit();</script>';
		return $html;
	}
}
