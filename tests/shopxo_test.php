<?php
/**
 * ShopXO 插件测试（由 run.php 引入）
 */

namespace {
    function DataReturn($msg = '', $code = 0, $data = '')
    {
        return ['msg' => $msg, 'code' => $code, 'data' => $data];
    }

    require dirname(__DIR__) . '/shopxo/extend/payment/Kpay.php';

    class TestShopxoKpay extends \payment\Kpay
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
        check(\payment\Kpay::sign($case['params'], VECTOR_KEY) === $case['sign'], "shopxo: 签名向量 #{$i}");
    }

    $sxConfig = (new \payment\Kpay())->Config();
    $names = array_column($sxConfig['element'], 'name');
    check($sxConfig['base']['name'] === 'KPay 凯付' && in_array('pid', $names, true) && in_array('key', $names, true), 'shopxo: 插件配置');

    $shopxo = new TestShopxoKpay(['api_url' => 'https://api.kaipay.cn/', 'pid' => '1001', 'key' => VECTOR_KEY, 'pay_type' => 'wxpay']);
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0)';
    $shopxo->responses[] = ['code' => 1, 'payurl' => 'https://api.kaipay.cn/checkout/sx'];
    $ret = $shopxo->Pay(['order_no' => '20261001000001', 'name' => '商品 & 赠品', 'total_price' => '58.8', 'notify_url' => 'https://shop.example.com/payment_order_kpay_notify.php', 'call_back_url' => 'https://shop.example.com/payment_order_kpay_respond.php']);
    parse_str($shopxo->requests[0]['body'], $sent);
    check($ret === ['msg' => 'success', 'code' => 0, 'data' => 'https://api.kaipay.cn/checkout/sx'], 'shopxo: 下单返回付款页');
    check($sent['type'] === 'wxpay' && $sent['money'] === '58.80' && $sent['device'] === 'pc' && \payment\Kpay::sign($sent, VECTOR_KEY) === $sent['sign'], 'shopxo: 下单参数和签名');
    $shopxo->responses[] = ['code' => -1, 'msg' => '商户未开通'];
    check($shopxo->Pay(['order_no' => '1', 'name' => 'x', 'total_price' => 1, 'notify_url' => 'n', 'call_back_url' => 'c'])['msg'] === '商户未开通', 'shopxo: 下单失败提示原因');
    check((new \payment\Kpay([]))->Pay(['order_no' => '1'])['code'] === -1, 'shopxo: 未配置时拒绝下单');

    $notify = ['pid' => '1001', 'trade_no' => 'P5', 'out_trade_no' => '20261001000001', 'type' => 'wxpay', 'name' => '商品 & 赠品', 'money' => '58.80', 'trade_status' => 'TRADE_SUCCESS'];
    $notify['sign'] = \payment\Kpay::sign($notify, VECTOR_KEY);
    $notify['sign_type'] = 'MD5';
    $_SERVER['QUERY_STRING'] = http_build_query($notify);
    $ret = $shopxo->Respond(['s' => 'api/ordernotify/notify']);
    check($ret['code'] === 0 && $ret['data']['trade_no'] === 'P5' && $ret['data']['out_trade_no'] === '20261001000001' && $ret['data']['pay_price'] === '58.80', 'shopxo: 合法通知返回统一格式');
    $_SERVER['QUERY_STRING'] = 's=/api/ordernotify/notify&' . http_build_query(array_merge($notify, ['money' => '0.01']));
    check($shopxo->Respond([])['code'] === -1, 'shopxo: 篡改金额被拒');
    $_SERVER['QUERY_STRING'] = 's=/api/ordernotify/notify&' . http_build_query($notify);
    check($shopxo->Respond([])['code'] === 0, 'shopxo: 入口文件的路由参数 s 不影响验签');
    $_SERVER['QUERY_STRING'] = '';

    check($shopxo->Refund(['order_no' => '1', 'trade_no' => 'P5', 'refund_price' => '1.00'])['msg'] === '未配置退款 API Key，请到 KPay 商户后台操作退款', 'shopxo: 未配置退款 Key');
    $refundXo = new TestShopxoKpay(['pid' => '1001', 'key' => VECTOR_KEY, 'refund_api_key' => 'ak', 'refund_api_secret' => 'sk']);
    $refundXo->responses[] = ['code' => 0];
    $ret = $refundXo->Refund(['order_no' => '20261001000001', 'trade_no' => 'P5', 'refund_price' => '10.50']);
    $req = $refundXo->requests[0];
    $h = [];
    foreach ($req['headers'] as $line) {
        list($name, $value) = explode(':', $line, 2);
        $h[strtolower($name)] = trim($value);
    }
    $canonical = implode("\n", ['POST', '/pay/api/order/refund', $h['x-kpay-timestamp'], $h['x-kpay-nonce'], hash('sha256', $req['body'])]);
    check($ret['code'] === 0 && hash_equals(hash_hmac('sha256', $canonical, 'sk'), $h['x-kpay-signature']), 'shopxo: 退款成功且请求签名正确');
    check(json_decode($req['body'], true)['refundRequestNo'] === '20261001000001R1050', 'shopxo: 同一笔退款的请求号固定');
}
