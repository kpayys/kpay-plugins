<?php
/**
 * PHP SDK 测试（由 run.php 引入）
 */

namespace {
    require dirname(__DIR__) . '/sdk/php/KPay.php';

    class TestSdkClient extends \KPay\Client
    {
        public $requests = [];
        public $responses = [];
        protected function request($method, $url, $body, array $headers)
        {
            $this->requests[] = compact('method', 'url', 'body', 'headers');
            return array_shift($this->responses);
        }
    }

    foreach (VECTOR_CASES as $i => $case) {
        check(\KPay\Client::sign($case['params'], VECTOR_KEY) === $case['sign'], "sdk-php: 签名向量 #{$i}");
    }

    $sdk = new TestSdkClient('1001', VECTOR_KEY, 'https://api.kaipay.cn/epay/', 'ak', VECTORS['hmac']['secret']);
    $url = $sdk->payUrl(['out_trade_no' => 'A1', 'name' => '会员 & 充值', 'money' => '9.9', 'notify_url' => 'https://shop.example.com/n', 'type' => 'alipay', 'evil' => 'x']);
    parse_str(parse_url($url, PHP_URL_QUERY), $sent);
    check(strpos($url, 'https://api.kaipay.cn/epay/submit?') === 0 && $sent['money'] === '9.90' && !isset($sent['evil']), 'sdk-php: 跳转下单地址');
    check(\KPay\Client::sign($sent, VECTOR_KEY) === $sent['sign'], 'sdk-php: 下单签名');
    check(throws(function () use ($sdk) { $sdk->payUrl(['out_trade_no' => 'A1']); }) === '缺少参数 name', 'sdk-php: 缺少参数报错');

    $notify = VECTOR_CASES[2]['params'];
    $notify['sign'] = VECTOR_CASES[2]['sign'];
    check($sdk->verifyNotify($notify) && !$sdk->verifyNotify(array_merge($notify, ['money' => '1'])), 'sdk-php: 通知验签');

    $sdk->responses[] = ['code' => -1, 'msg' => '签名验证失败'];
    check(throws(function () use ($sdk) { $sdk->createOrder(['out_trade_no' => 'A1', 'name' => 'n', 'money' => 1, 'notify_url' => 'https://x.example.com/n']); }) === '签名验证失败', 'sdk-php: 下单失败抛出原因');

    $sdk->responses[] = ['code' => 0];
    $sdk->refund('P20260930120000001', 1.5, 'R2026093012345', '易支付后台退款');
    $last = end($sdk->requests);
    check($last['body'] === VECTORS['hmac']['body'] && $last['url'] === 'https://api.kaipay.cn/pay/api/order/refund', 'sdk-php: 退款请求体与向量一致');
    $h = VECTORS['hmac'];
    check(in_array('X-KPay-Signature: ' . $h['signature'], $sdk->hmacHeaders('POST', $h['requestUri'], $h['body'], $h['timestamp'], $h['nonce']), true), 'sdk-php: HMAC 向量');
}
