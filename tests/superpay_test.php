<?php
/**
 * 超级支付插件测试（PHP 8+，由 run.php 引入）
 */

namespace app\common\controller {
    /**
     * BasePay 的最小替身：订单从 $orders 里取，页面动作返回数组方便断言
     */
    class BasePay
    {
        public static $orders = [];
        public $lastError = '';

        protected function loadOrder($tradeNo)
        {
            if (!isset(self::$orders[$tradeNo])) {
                throw new \RuntimeException('订单不存在');
            }
            return self::$orders[$tradeNo];
        }

        protected function withPaying($order, $callback)
        {
            return $callback($order);
        }

        protected function redirect($url)
        {
            return ['redirect' => $url];
        }

        protected function qrcode($order, $url)
        {
            return ['qrcode' => $url];
        }

        protected function error($msg = '', $data = null, $code = 0, $type = '')
        {
            return ['error' => $msg];
        }

        protected function setError($msg)
        {
            $this->lastError = $msg;
        }
    }
}

namespace app\payApi\controller {
    function url($route, $vars = [], $suffix = true, $domain = false)
    {
        return 'https://sp.example.com/' . $route . ($vars ? '?' . http_build_query($vars) : '');
    }

    function record_order_log($orderId, $message, $status = 'info')
    {
        $GLOBALS['__sp_logs'][] = $message;
    }
}

namespace {
    require dirname(__DIR__) . '/superpay/php/app/payApi/controller/Kpay.php';

    class FakeSuperpayOrder
    {
        public $id = 1;
        public $trade_no = '2026100112000001';
        public $subject = '会员 & 充值 = 1';
        public $total_amount = '99.90';
        public $status = 0;
        public $transaction_id = '';
        public $channelAccount;
        public $completed = 0;

        public function completeOrder()
        {
            $this->completed++;
            $this->status = 1;
        }

        public function callbackUrl()
        {
            return 'https://merchant.example.com/return';
        }
    }

    class TestKpayController extends \app\payApi\controller\Kpay
    {
        public $requests = [];
        public $responses = [];

        protected function request(string $method, string $url, ?string $body, array $headers): array
        {
            $this->requests[] = compact('method', 'url', 'body', 'headers');
            return array_shift($this->responses);
        }

        public function callRefund($order, $amount)
        {
            return \Closure::bind(function ($order, $amount) { return $this->refund($order, $amount); }, $this, \app\payApi\controller\Kpay::class)($order, $amount);
        }

        public function callQuery($order)
        {
            return \Closure::bind(function ($order) { return $this->query($order); }, $this, \app\payApi\controller\Kpay::class)($order);
        }
    }

    $Kpay = '\app\payApi\controller\Kpay';

    foreach (VECTOR_CASES as $i => $case) {
        check($Kpay::sign($case['params'], VECTOR_KEY) === $case['sign'], "superpay: 签名向量 #{$i}");
    }
    check($Kpay::gateway('') === 'https://api.kaipay.cn/' && $Kpay::gateway('https://api.kaipay.cn/epay') === 'https://api.kaipay.cn/', 'superpay: 网关地址');
    check($Kpay::payType('bank') === 'unionpay' && $Kpay::payType('cashier') === '', 'superpay: 支付方式映射');

    $_SERVER['QUERY_STRING'] = 's=/payApi/Kpay/notify&' . http_build_query(array_merge(VECTOR_CASES[2]['params'], ['sign' => VECTOR_CASES[2]['sign'], 'sign_type' => 'MD5']));
    $query = $Kpay::rawQuery();
    check(!isset($query['s']) && $query['name'] === '会员 & 充值 = 1', 'superpay: 通知参数取原始值并去掉路由参数');

    $order = new FakeSuperpayOrder();
    $order->trade_no = '2026093012345';
    $order->channelAccount = (object)['params' => (object)['api_url' => 'https://api.kaipay.cn/', 'pid' => '1001', 'key' => VECTOR_KEY, 'pay_type' => 'wxpay']];
    \app\common\controller\BasePay::$orders[$order->trade_no] = $order;

    $controller = new TestKpayController();
    check($controller->handleNotify($query) === 'success' && $order->completed === 1 && $order->transaction_id === 'P20260930120000001', 'superpay: 合法通知完成订单');
    check($controller->handleNotify($query) === 'success' && $order->completed === 1, 'superpay: 重复通知不重复入账');
    $order->status = 0;
    check($controller->handleNotify(array_merge($query, ['money' => '0.01'])) === 'fail', 'superpay: 篡改金额被拒');
    $order->total_amount = '100.00';
    check($controller->handleNotify($query) === 'fail', 'superpay: 金额与订单不符不入账');
    $order->total_amount = '99.90';
    $order->channelAccount->params->pid = '1002';
    check($controller->handleNotify($query) === 'fail', 'superpay: 其他商户的通知被拒');
    $order->channelAccount->params->pid = '1001';
    check($controller->handleNotify(array_merge($query, ['out_trade_no' => 'x'])) === 'fail', 'superpay: 订单不存在');

    $order->status = 0;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) MicroMessenger';
    $controller->responses[] = ['code' => 1, 'payurl' => 'https://api.kaipay.cn/checkout/abc', 'qrcode' => ''];
    $result = $controller->pay($order->trade_no);
    $sent = [];
    parse_str($controller->requests[0]['body'], $sent);
    check($result === ['redirect' => 'https://api.kaipay.cn/checkout/abc'], 'superpay: 下单后跳转付款页');
    check($controller->requests[0]['url'] === 'https://api.kaipay.cn/epay/mapi', 'superpay: 调 mapi 下单');
    check($sent['type'] === 'wxpay' && $sent['device'] === 'mobile' && $sent['money'] === '99.90' && !isset($sent['clientip']), 'superpay: 下单参数');
    check($sent['notify_url'] === 'https://sp.example.com/payApi/Kpay/notify', 'superpay: 通知地址');
    check($Kpay::sign($sent, VECTOR_KEY) === $sent['sign'], 'superpay: 下单签名正确');

    $controller->responses[] = ['code' => -1, 'msg' => '商户未开通'];
    check($controller->pay($order->trade_no) === ['error' => '商户未开通'], 'superpay: 下单失败提示原因');
    $controller->responses[] = ['code' => 1, 'payurl' => '', 'qrcode' => 'weixin://wxpay/bizpayurl?pr=abc'];
    check($controller->pay($order->trade_no) === ['qrcode' => 'weixin://wxpay/bizpayurl?pr=abc'], 'superpay: 只有二维码时展示扫码页');

    check($controller->callRefund($order, 1) === false && strpos($controller->lastError, '未配置退款') !== false, 'superpay: 未配置退款 Key 时拒绝');
    $order->channelAccount->params->refund_api_key = 'ak';
    $order->channelAccount->params->refund_api_secret = 'sk';
    $controller->requests = [];
    $controller->responses[] = ['code' => 0, 'msg' => '退款已受理'];
    check($controller->callRefund($order, 1.5) === true, 'superpay: 退款成功');
    $refundRequest = $controller->requests[0];
    $h = [];
    foreach ($refundRequest['headers'] as $line) {
        list($name, $value) = explode(':', $line, 2);
        $h[strtolower($name)] = trim($value);
    }
    $canonical = implode("\n", ['POST', '/pay/api/order/refund', $h['x-kpay-timestamp'], $h['x-kpay-nonce'], hash('sha256', $refundRequest['body'])]);
    check($refundRequest['url'] === 'https://api.kaipay.cn/pay/api/order/refund' && hash_equals(hash_hmac('sha256', $canonical, 'sk'), $h['x-kpay-signature']), 'superpay: 退款请求签名正确');
    check(json_decode($refundRequest['body'], true)['orderNo'] === 'P20260930120000001', 'superpay: 按 KPay 单号退款');

    $controller->responses[] = ['code' => 1, 'trade_no' => 'P1', 'trade_status' => 'TRADE_SUCCESS', 'money' => '99.90'];
    $queried = $controller->callQuery($order);
    check($queried['status'] === 1 && $queried['transaction_id'] === 'P1', 'superpay: 查单');
}
