<?php
/**
 * 智简魔方 V10 插件测试（由 run.php 引入）
 */

namespace app\common\lib {
    class Plugin
    {
        public static $config = [];
        public function getConfig() { return self::$config; }
        public function getName() { return 'kpay'; }
    }
}

namespace app\home\controller {
    class BaseController
    {
    }
}

namespace {
    $GLOBALS['__zj_paid'] = [];

    function configuration($key) { return $key === 'website_url' ? 'https://idc.example.com/' : ''; }
    function order_pay_handle(array $data) { $GLOBALS['__zj_paid'][] = $data; }

    $zjRoot = dirname(__DIR__) . '/zjmf/public/plugins/gateway/kpay';
    require $zjRoot . '/Kpay.php';
    require $zjRoot . '/controller/IndexController.php';
    $zjConfig = require $zjRoot . '/config.php';

    class TestZjmfClient extends \gateway\kpay\lib\KpayClient
    {
        public $requests = [];
        public $responses = [];
        protected function request($method, $url, $body, array $headers)
        {
            $this->requests[] = compact('method', 'url', 'body', 'headers');
            return array_shift($this->responses);
        }
    }

    $Client = '\gateway\kpay\lib\KpayClient';
    foreach (VECTOR_CASES as $i => $case) {
        check($Client::sign($case['params'], VECTOR_KEY) === $case['sign'], "zjmf: 签名向量 #{$i}");
    }
    $allHaveValue = true;
    foreach ($zjConfig as $item) {
        $allHaveValue = $allHaveValue && array_key_exists('value', $item);
    }
    check($allHaveValue && isset($zjConfig['module_name']) && !isset($zjConfig['notify_url']), 'zjmf: 配置项符合 V10 约定');
    check(file_exists($zjRoot . '/Kpay.png'), 'zjmf: 带插件图标');

    $plugin = new \gateway\kpay\Kpay();
    check($plugin->info['name'] === 'Kpay' && method_exists($plugin, 'KpayHandle') && method_exists($plugin, 'KpayHandleRefund'), 'zjmf: 插件标识和入口方法');
    \app\common\lib\Plugin::$config = ['pid' => '', 'key' => ''];
    check(strpos($plugin->KpayHandle(['out_trade_no' => 'T1', 'finance' => ['total' => 1]]), 'KPay 商户ID或密钥未配置') !== false, 'zjmf: 未配置时收银台提示原因');
    check(\gateway\kpay\Kpay::subject(['product' => ['香港 VPS 月付'], 'out_trade_no' => 'T1']) === '香港 VPS 月付', 'zjmf: 订单标题取商品名');
    check(strpos(\gateway\kpay\Kpay::payButtonHtml('https://a.example.com/?x=1&y="2"'), 'x=1&amp;y=&quot;2&quot;') !== false, 'zjmf: 付款链接做了 HTML 转义');

    $config = ['apiurl' => 'https://api.kaipay.cn/', 'pid' => '1001', 'key' => VECTOR_KEY, 'pay_type' => 'cashier'];
    $client = new TestZjmfClient($config);
    $params = $client->orderParams('1790800000123456', '30', '香港 VPS 月付', 'https://idc.example.com/gateway/kpay/index/notifyHandle', 'https://idc.example.com/gateway/kpay/index/returnHandle', 'pc');
    check(!isset($params['type']) && $params['money'] === '30.00' && $params['out_trade_no'] === '1790800000123456', 'zjmf: 收银台模式不指定支付方式');
    check($Client::sign($params, VECTOR_KEY) === $params['sign'], 'zjmf: 下单签名正确');
    $client->responses[] = ['code' => 1, 'payurl' => 'https://api.kaipay.cn/checkout/x'];
    check($client->createOrder($params) === 'https://api.kaipay.cn/checkout/x' && $client->requests[0]['url'] === 'https://api.kaipay.cn/epay/mapi', 'zjmf: mapi 下单拿到付款页');

    $notify = ['pid' => '1001', 'trade_no' => 'P7', 'out_trade_no' => '1790800000123456', 'type' => 'alipay', 'name' => '香港 VPS 月付', 'money' => '30.00', 'trade_status' => 'TRADE_SUCCESS'];
    $notify['sign'] = $Client::sign($notify, VECTOR_KEY);
    check(!\gateway\kpay\controller\IndexController::handle(array_merge($notify, ['money' => '0.01']), $config) && !$GLOBALS['__zj_paid'], 'zjmf: 篡改金额被拒');
    check(!\gateway\kpay\controller\IndexController::handle($notify, array_merge($config, ['pid' => '1002'])), 'zjmf: 其他商户被拒');
    check(\gateway\kpay\controller\IndexController::handle($notify, $config), 'zjmf: 合法通知入账');
    $paid = $GLOBALS['__zj_paid'][0];
    check($paid['tmp_order_id'] === '1790800000123456' && $paid['amount'] === '30.00' && $paid['trans_id'] === 'P7' && $paid['gateway'] === 'Kpay', 'zjmf: 入账参数');

    check(throws(function () use ($client) { $client->refund('P7', 1, 'R1'); }) === '未配置退款 API Key，请到 KPay 商户后台操作退款', 'zjmf: 未配置退款 Key');
    $refundClient = new TestZjmfClient(array_merge($config, ['refund_api_key' => 'ak', 'refund_api_secret' => 'sk']));
    $refundClient->responses[] = ['code' => 0];
    $refundClient->refund('P7', 5, 'R1');
    $req = $refundClient->requests[0];
    $h = [];
    foreach ($req['headers'] as $line) {
        list($name, $value) = explode(':', $line, 2);
        $h[strtolower($name)] = trim($value);
    }
    $canonical = implode("\n", ['POST', '/pay/api/order/refund', $h['x-kpay-timestamp'], $h['x-kpay-nonce'], hash('sha256', $req['body'])]);
    check(hash_equals(hash_hmac('sha256', $canonical, 'sk'), $h['x-kpay-signature']), 'zjmf: 退款请求签名正确');
    \app\common\lib\Plugin::$config = $config;
    $refundResult = $plugin->KpayHandleRefund(['transaction_number' => 'P7', 'amount' => 5, 'out_request_no' => 'R2']);
    check($refundResult['status'] === 400 && strpos($refundResult['msg'], '退款 API Key') !== false, 'zjmf: 插件退款入口返回 V10 约定的错误结构');
}
