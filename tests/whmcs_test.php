<?php
/**
 * WHMCS 模块测试（由 run.php 引入）
 */

define('WHMCS', true);
require dirname(__DIR__) . '/whmcs/modules/gateways/kpay.php';

class TestWhmcsKpayGateway extends KpayGateway
{
    public $requests = [];
    public $responses = [];

    protected function request($method, $url, $body, array $headers)
    {
        $this->requests[] = compact('method', 'url', 'body', 'headers');
        return array_shift($this->responses);
    }
}

$whmcsParams = ['apiUrl' => 'https://api.kaipay.cn/epay/', 'pid' => '1001', 'key' => VECTOR_KEY, 'payType' => 'wxpay', 'subject' => '账单 {invoiceid}'];

foreach (VECTOR_CASES as $i => $case) {
    check(KpayGateway::sign($case['params'], VECTOR_KEY) === $case['sign'], "whmcs: 签名向量 #{$i}");
}

$meta = kpay_MetaData();
$config = kpay_config();
check($meta['DisplayName'] === 'KPay 凯付' && isset($config['pid'], $config['key'], $config['refundApiKey']), 'whmcs: 模块元数据和配置项');

$gateway = new TestWhmcsKpayGateway($whmcsParams);
$params = $gateway->orderParams(123, '88.5', 'https://whmcs.example.com/modules/gateways/callback/kpay.php', 'https://whmcs.example.com/viewinvoice.php?id=123', 1790800000);
check($params['out_trade_no'] === '123T1790800000' && $params['money'] === '88.50' && $params['type'] === 'wxpay', 'whmcs: 下单参数');
check($params['name'] === '账单 123', 'whmcs: 订单标题');
check(KpayGateway::sign($params, VECTOR_KEY) === $params['sign'], 'whmcs: 下单签名正确');
check(KpayGateway::invoiceIdFromOutTradeNo('123T1790800000') === 123 && KpayGateway::invoiceIdFromOutTradeNo('abc') === 0, 'whmcs: 从订单号取回账单号');

$html = kpay_link(array_merge($whmcsParams, ['invoiceid' => 123, 'amount' => '88.50', 'systemurl' => 'https://whmcs.example.com/', 'returnurl' => 'https://whmcs.example.com/viewinvoice.php?id=123&a=b', 'langpaynow' => '立即支付']));
check(strpos($html, 'action="https://api.kaipay.cn/epay/submit"') !== false, 'whmcs: 付款按钮提交到 /epay/submit');
check(strpos($html, 'id=123&amp;a=b') !== false, 'whmcs: 表单值做了 HTML 转义');
check(strpos(kpay_link(['pid' => '', 'key' => '', 'invoiceid' => 1, 'amount' => 1, 'systemurl' => '', 'returnurl' => '', 'langpaynow' => '']), 'alert-danger') !== false, 'whmcs: 未配置时显示错误');

$notify = ['pid' => '1001', 'trade_no' => 'P1', 'out_trade_no' => '123T1790800000', 'type' => 'wxpay', 'name' => '账单 123', 'money' => '88.50', 'trade_status' => 'TRADE_SUCCESS'];
$notify['sign'] = KpayGateway::sign($notify, VECTOR_KEY);
$notify['sign_type'] = 'MD5';
check($gateway->parseNotify($notify) === [123, 'P1', '88.50'], 'whmcs: 合法通知解析出账单号、单号、金额');
check($gateway->parseNotify(array_merge($notify, ['money' => '1.00'])) === null, 'whmcs: 篡改金额被拒');
check($gateway->parseNotify(array_merge($notify, ['pid' => '1002'])) === null, 'whmcs: 其他商户被拒');

check(throws(function () use ($gateway) { $gateway->refund('P1', 1, 'R1'); }) === '未配置退款 API Key，请到 KPay 商户后台操作退款', 'whmcs: 未配置退款 Key');
$refundGateway = new TestWhmcsKpayGateway(array_merge($whmcsParams, ['refundApiKey' => 'ak', 'refundApiSecret' => 'sk']));
$refundGateway->responses[] = ['code' => 0, 'msg' => 'ok'];
$refundGateway->refund('P1', 10.5, 'R1');
$req = $refundGateway->requests[0];
$h = [];
foreach ($req['headers'] as $line) {
    list($name, $value) = explode(':', $line, 2);
    $h[strtolower($name)] = trim($value);
}
$canonical = implode("\n", ['POST', '/pay/api/order/refund', $h['x-kpay-timestamp'], $h['x-kpay-nonce'], hash('sha256', $req['body'])]);
check($req['url'] === 'https://api.kaipay.cn/pay/api/order/refund' && hash_equals(hash_hmac('sha256', $canonical, 'sk'), $h['x-kpay-signature']), 'whmcs: 退款请求签名正确');
$refundGateway->responses[] = ['code' => 7, 'msg' => '无退款权限'];
check(throws(function () use ($refundGateway) { $refundGateway->refund('P1', 1, 'R2'); }) === '无退款权限', 'whmcs: 退款失败原样提示');
$hv = VECTORS['hmac'];
check(in_array('X-KPay-Signature: ' . $hv['signature'], KpayGateway::hmacHeaders('ak', $hv['secret'], 'POST', $hv['requestUri'], $hv['body'], $hv['timestamp'], $hv['nonce']), true), 'whmcs: HMAC 签名向量');
