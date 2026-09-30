<?php

namespace Paymenter\Extensions\Gateways\Kpay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Exception;
use Illuminate\Http\Request;

#[ExtensionMeta(
    name: 'KPay',
    description: 'Accept Alipay, WeChat Pay and UnionPay (CNY) via KPay.',
    version: '1.0.0',
    author: 'KPay',
    url: 'https://github.com/kpayys/kpay-plugins/tree/main/paymenter',
)]
class Kpay extends Gateway
{
    const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'api_url',
                'label' => 'API URL',
                'type' => 'text',
                'default' => self::DEFAULT_GATEWAY,
                'description' => 'Usually https://api.kaipay.cn/',
                'required' => true,
            ],
            [
                'name' => 'pid',
                'label' => 'Merchant ID (pid)',
                'type' => 'text',
                'description' => 'KPay dashboard → EPay → EPay config',
                'required' => true,
            ],
            [
                'name' => 'key',
                'label' => 'EPay Key',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Shown once when you create an "EPay compatible" key on the KPay API keys page',
                'required' => true,
            ],
            [
                'name' => 'pay_type',
                'label' => 'Payment method',
                'type' => 'select',
                'default' => 'cashier',
                'options' => [
                    'cashier' => 'KPay checkout (payer chooses)',
                    'alipay' => 'Alipay',
                    'wxpay' => 'WeChat Pay',
                    'unionpay' => 'UnionPay',
                ],
            ],
        ];
    }

    /**
     * 返回 KPay 付款页地址，Paymenter 负责跳转。KPay 只收人民币
     */
    public function pay(Invoice $invoice, $total)
    {
        if (strtoupper((string)$invoice->currency_code) !== 'CNY') {
            throw new Exception('KPay only accepts CNY invoices.');
        }
        $pid = trim((string)$this->config('pid'));
        $key = trim((string)$this->config('key'));
        if ($pid === '' || $key === '') {
            throw new Exception('KPay merchant ID or EPay Key is not configured.');
        }

        $params = self::orderParams(
            $pid,
            $key,
            (string)$this->config('pay_type'),
            $invoice->id,
            $total,
            route('extensions.gateways.kpay.notify'),
            route('invoices.show', $invoice),
            (string)request()->userAgent()
        );
        return self::gateway($this->config('api_url')) . 'epay/submit?' . http_build_query($params);
    }

    /**
     * KPay 以 GET 方式通知。直接解析原始查询串，不经过 TrimStrings 等中间件
     */
    public function notify(Request $request)
    {
        $data = [];
        parse_str((string)$request->server('QUERY_STRING'), $data);
        $parsed = self::parseNotify($data, (string)$this->config('pid'), (string)$this->config('key'));
        if ($parsed === null) {
            return response('fail', 400);
        }
        // 同一个 transaction_id 重复通知时 addPayment 会更新原记录，不会重复入账
        ExtensionHelper::addPayment($parsed['invoice_id'], 'Kpay', $parsed['amount'], transactionId: $parsed['trade_no']);
        return response('success');
    }

    // ─── 纯函数 ─────────────────────────────────────────────────────

    public static function orderParams($pid, $key, $payType, $invoiceId, $total, $notifyUrl, $returnUrl, $userAgent, $time = null)
    {
        $params = [
            'pid' => $pid,
            'out_trade_no' => (int)$invoiceId . 'T' . ($time ?? time()),
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'name' => 'Invoice #' . (int)$invoiceId,
            'money' => number_format((float)$total, 2, '.', ''),
            'device' => ($userAgent !== '' && preg_match('/Mobile|Android|iPhone|iPad|iPod|MicroMessenger|AlipayClient|HarmonyOS/i', $userAgent)) ? 'mobile' : 'pc',
        ];
        $type = in_array($payType, ['alipay', 'wxpay', 'unionpay'], true) ? $payType : '';
        if ($type !== '') {
            $params['type'] = $type;
        }
        $params['sign'] = self::sign($params, $key);
        $params['sign_type'] = 'MD5';
        return $params;
    }

    public static function parseNotify(array $data, $pid, $key)
    {
        if (!self::verify($data, $pid, $key) || ($data['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return null;
        }
        if (!preg_match('/^(\d+)T\d+$/', (string)($data['out_trade_no'] ?? ''), $m) || empty($data['trade_no'])) {
            return null;
        }
        return ['invoice_id' => (int)$m[1], 'trade_no' => (string)$data['trade_no'], 'amount' => (string)$data['money']];
    }

    public static function gateway($url)
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://[^/\s]+#i', $url)) {
            return self::DEFAULT_GATEWAY;
        }
        return preg_replace('#/epay$#i', '', rtrim($url, '/')) . '/';
    }

    public static function sign(array $params, $key)
    {
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $name => $value) {
            $name = (string)$name;
            if ($name === 'sign' || $name === 'sign_type' || !is_scalar($value)) {
                continue;
            }
            $value = (string)$value;
            if (trim($value) === '') {
                continue;
            }
            $pairs[] = $name . '=' . $value;
        }
        return md5(implode('&', $pairs) . $key);
    }

    public static function verify(array $data, $pid, $key)
    {
        $pid = trim((string)$pid);
        $key = trim((string)$key);
        if ($pid === '' || $key === '' || !isset($data['sign']) || !is_string($data['sign'])) {
            return false;
        }
        if (!isset($data['pid']) || (string)$data['pid'] !== $pid) {
            return false;
        }
        return hash_equals(self::sign($data, $key), strtolower(trim($data['sign'])));
    }
}
