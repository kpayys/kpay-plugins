<?php
/**
 * Paymenter 扩展测试（PHP 8+，由 run.php 引入）
 */

namespace App\Classes\Extension {
    abstract class Gateway
    {
        public function __construct(public $config = []) {}
        public function config($key) { return $this->config[$key] ?? null; }
        abstract public function pay(\App\Models\Invoice $invoice, $total);
    }
}

namespace App\Models {
    class Invoice
    {
        public function __construct(public $id, public $currency_code = 'CNY') {}
    }
}

namespace App\Helpers {
    class ExtensionHelper
    {
        public static $payments = [];
        public static function addPayment($invoice, $gateway, $amount, $fee = null, $transactionId = null)
        {
            self::$payments[] = compact('invoice', 'gateway', 'amount', 'transactionId');
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private array $server = []) {}
        public function server($key) { return $this->server[$key] ?? null; }
    }
}

namespace Paymenter\Extensions\Gateways\Kpay {
    function route($name, $params = null)
    {
        return $name === 'extensions.gateways.kpay.notify' ? 'https://billing.example.com/extensions/gateways/kpay/notify' : 'https://billing.example.com/invoices/' . $params->id;
    }

    function request()
    {
        return new class {
            public function userAgent() { return 'Mozilla/5.0 (iPhone) Mobile'; }
        };
    }

    function response($body, $status = 200)
    {
        return ['body' => $body, 'status' => $status];
    }
}

namespace {
    require dirname(__DIR__) . '/paymenter/extensions/Gateways/Kpay/Kpay.php';
    $PK = '\Paymenter\Extensions\Gateways\Kpay\Kpay';

    foreach (VECTOR_CASES as $i => $case) {
        check($PK::sign($case['params'], VECTOR_KEY) === $case['sign'], "paymenter: 签名向量 #{$i}");
    }

    $ext = new \Paymenter\Extensions\Gateways\Kpay\Kpay(['api_url' => 'https://api.kaipay.cn/', 'pid' => '1001', 'key' => VECTOR_KEY, 'pay_type' => 'alipay']);
    check(count($ext->getConfig()) === 4, 'paymenter: 配置项');
    $url = $ext->pay(new \App\Models\Invoice(42), 128);
    parse_str(parse_url($url, PHP_URL_QUERY), $sent);
    check(strpos($url, 'https://api.kaipay.cn/epay/submit?') === 0, 'paymenter: 返回 KPay 付款地址');
    check(preg_match('/^42T\d+$/', $sent['out_trade_no']) === 1 && $sent['money'] === '128.00' && $sent['type'] === 'alipay' && $sent['device'] === 'mobile', 'paymenter: 下单参数');
    check($sent['notify_url'] === 'https://billing.example.com/extensions/gateways/kpay/notify' && $PK::sign($sent, VECTOR_KEY) === $sent['sign'], 'paymenter: 通知地址和签名');
    check(throws(function () use ($ext) { $ext->pay(new \App\Models\Invoice(43, 'USD'), 10); }) === 'KPay only accepts CNY invoices.', 'paymenter: 非人民币账单拒绝');

    $notify = ['pid' => '1001', 'trade_no' => 'P42', 'out_trade_no' => '42T1790800000', 'type' => 'alipay', 'name' => 'Invoice #42', 'money' => '128.00', 'trade_status' => 'TRADE_SUCCESS'];
    $notify['sign'] = $PK::sign($notify, VECTOR_KEY);
    $notify['sign_type'] = 'MD5';
    $res = $ext->notify(new \Illuminate\Http\Request(['QUERY_STRING' => http_build_query(array_merge($notify, ['money' => '1.00']))]));
    check($res['status'] === 400 && !\App\Helpers\ExtensionHelper::$payments, 'paymenter: 篡改金额被拒');
    $res = $ext->notify(new \Illuminate\Http\Request(['QUERY_STRING' => http_build_query($notify)]));
    $payment = \App\Helpers\ExtensionHelper::$payments[0] ?? [];
    check($res['body'] === 'success' && $payment['invoice'] === 42 && $payment['gateway'] === 'Kpay' && $payment['amount'] === '128.00' && $payment['transactionId'] === 'P42', 'paymenter: 合法通知入账');
    check($PK::parseNotify($notify, '1002', VECTOR_KEY) === null, 'paymenter: 其他商户被拒');
}
