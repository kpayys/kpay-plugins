<?php

namespace app\payApi\controller;

use app\common\controller\BasePay;

/**
 * 超级支付 × KPay 支付接口
 * 接口编码：Kpay
 *
 * 通过 KPay 的易支付兼容接口收款。一个渠道账号对应一个 KPay 商户，
 * 服务商模式下给每个下级商户各建一个渠道账号即可。
 */
class Kpay extends BasePay
{
    private const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

    public static $config = [
        'types' => 0,
        'name' => 'KPay 凯付',
        'account_fields' => [
            [
                'id' => 'api_url',
                'name' => '接口地址',
                'type' => 'input',
                'required' => true,
                'placeholder' => 'https://api.kaipay.cn/',
                'remark' => '一般不用改',
            ],
            [
                'id' => 'pid',
                'name' => '商户ID',
                'type' => 'input',
                'required' => true,
                'placeholder' => 'KPay 后台「EPay 配置」里的商户ID',
            ],
            [
                'id' => 'key',
                'name' => '商户密钥',
                'type' => 'textarea',
                'required' => true,
                'placeholder' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key',
            ],
            [
                'id' => 'pay_type',
                'name' => '支付方式',
                'type' => 'radio',
                'required' => true,
                'data' => [
                    ['name' => '支付宝', 'value' => 'alipay'],
                    ['name' => '微信支付', 'value' => 'wxpay'],
                    ['name' => '云闪付', 'value' => 'unionpay'],
                    ['name' => 'KPay 收银台（付款人自选）', 'value' => 'cashier'],
                ],
            ],
            [
                'id' => 'refund_api_key',
                'name' => '退款 API Key',
                'type' => 'input',
                'required' => false,
                'placeholder' => '选填',
                'remark' => 'KPay「API 密钥」里创建的平台 API 密钥（勾选「发起退款」权限）；不填则只能在 KPay 后台退款',
            ],
            [
                'id' => 'refund_api_secret',
                'name' => '退款 API Secret',
                'type' => 'textarea',
                'required' => false,
                'placeholder' => '选填，与上面的 API Key 配对',
            ],
        ],
        'remark' => '商户ID在 KPay 商户后台「EPay 接入 → EPay 配置」查看，EPay Key 在「API 密钥」页创建 EPay 兼容密钥时显示。一个渠道账号对应一个 KPay 商户。',
    ];

    public function pay($trade_no)
    {
        $order = $this->loadOrder($trade_no);

        return $this->withPaying($order, function ($order) use ($trade_no) {
            $account = $order->channelAccount->params;
            $pid = trim((string)($account->pid ?? ''));
            $key = trim((string)($account->key ?? ''));
            if ($pid === '' || $key === '') {
                return $this->error('KPay 商户ID或密钥未配置');
            }

            // 只传 KPay 用得上的字段；KPay 不会回传 param，也用不到 clientip
            $params = [
                'pid' => $pid,
                'out_trade_no' => $order->trade_no,
                'notify_url' => (string)url('payApi/Kpay/notify', [], false, true),
                'return_url' => (string)url('payApi/Kpay/callback', ['out_trade_no' => $trade_no], false, true),
                'name' => mb_substr((string)($order->subject ?: '订单 ' . $order->trade_no), 0, 64),
                'money' => number_format((float)$order->total_amount, 2, '.', ''),
                'device' => self::device(),
            ];
            $type = self::payType((string)($account->pay_type ?? ''));
            if ($type !== '') {
                $params['type'] = $type;
            }
            $params['sign'] = self::sign($params, $key);
            $params['sign_type'] = 'MD5';

            try {
                $result = $this->postForm(self::gateway($account->api_url ?? '') . 'epay/mapi', $params);
            } catch (\Throwable $e) {
                record_order_log($order->id, 'KPay 下单请求失败：' . $e->getMessage(), 'error');
                return $this->error('支付网关连接失败，请稍后重试');
            }

            if ((int)($result['code'] ?? 0) !== 1) {
                record_order_log($order->id, 'KPay 下单失败：' . json_encode($result, JSON_UNESCAPED_UNICODE), 'error');
                return $this->error($result['msg'] ?? '下单失败，请稍后重试');
            }

            $payUrl = trim((string)($result['payurl'] ?? ''));
            if (preg_match('#^https?://#i', $payUrl)) {
                return $this->redirect($payUrl);
            }
            $qrcode = trim((string)($result['qrcode'] ?? ''));
            if ($qrcode !== '') {
                return $this->qrcode($order, $qrcode);
            }
            return $this->error('支付网关未返回付款地址');
        });
    }

    public function callback()
    {
        $order = $this->loadOrder(input('out_trade_no/s', ''));
        return redirect($order->callbackUrl());
    }

    /**
     * KPay 以 GET 方式通知。直接解析原始查询串，不经过框架的默认过滤器，
     * 否则商品名里的 & 之类会被转义，验签对不上。
     */
    public function notify()
    {
        echo $this->handleNotify(self::rawQuery());
        exit;
    }

    public function handleNotify(array $data): string
    {
        try {
            $order = $this->loadOrder((string)($data['out_trade_no'] ?? ''));
        } catch (\Throwable $e) {
            return 'fail';
        }
        if (!$order) {
            return 'fail';
        }

        $account = $order->channelAccount->params;
        if (!self::verify($data, (string)($account->pid ?? ''), (string)($account->key ?? ''))) {
            record_order_log($order->id, 'KPay 通知验签失败', 'warning');
            return 'fail';
        }
        if (($data['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return 'fail';
        }
        // money 是下单时的金额，与订单金额一致才入账
        if (round((float)($data['money'] ?? 0), 2) != round((float)$order->total_amount, 2)) {
            record_order_log($order->id, 'KPay 通知金额与订单不符：' . ($data['money'] ?? ''), 'warning');
            return 'fail';
        }

        if ((int)($order->status ?? 0) !== 1) {
            $order->transaction_id = (string)($data['trade_no'] ?? '');
            $order->completeOrder();
            record_order_log($order->id, 'KPay 支付成功，平台单号 ' . $order->transaction_id, 'success');
        }
        return 'success';
    }

    private function refund($order, $amount)
    {
        $account = $order->channelAccount->params;
        $apiKey = trim((string)($account->refund_api_key ?? ''));
        $apiSecret = trim((string)($account->refund_api_secret ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            $this->setError('该渠道账号未配置退款 API Key，请到 KPay 商户后台退款');
            return false;
        }
        if (empty($order->transaction_id)) {
            $this->setError('缺少 KPay 平台订单号，无法退款');
            return false;
        }

        $path = '/pay/api/order/refund';
        $body = json_encode([
            'orderNo' => (string)$order->transaction_id,
            'refundAmount' => round((float)$amount, 2),
            'refundRequestNo' => 'R' . $order->trade_no . 'T' . date('YmdHis'),
            'reason' => '商户发起退款',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $result = $this->request('POST', rtrim(self::gateway($account->api_url ?? ''), '/') . $path, $body,
                array_merge(['Content-Type: application/json'], self::hmacHeaders($apiKey, $apiSecret, 'POST', $path, $body)));
        } catch (\Throwable $e) {
            $this->setError('连接 KPay 失败：' . $e->getMessage());
            return false;
        }
        if ((int)($result['code'] ?? -1) === 0) {
            record_order_log($order->id, 'KPay 退款已受理：' . $amount, 'success');
            return true;
        }
        $this->setError($result['msg'] ?? '退款失败');
        return false;
    }

    private function query($order)
    {
        $account = $order->channelAccount->params;
        $url = self::gateway($account->api_url ?? '') . 'epay/api?' . http_build_query([
            'act' => 'order',
            'pid' => (string)($account->pid ?? ''),
            'key' => (string)($account->key ?? ''),
            'out_trade_no' => $order->trade_no,
        ]);
        try {
            $result = $this->request('GET', $url, null, []);
        } catch (\Throwable $e) {
            $this->setError('连接 KPay 失败：' . $e->getMessage());
            return false;
        }
        if ((int)($result['code'] ?? 0) !== 1) {
            $this->setError($result['msg'] ?? '查询失败');
            return false;
        }
        return [
            'trade_no' => $order->trade_no,
            'transaction_id' => (string)($result['trade_no'] ?? ''),
            'status' => ($result['trade_status'] ?? '') === 'TRADE_SUCCESS' ? 1 : 0,
            'total_amount' => $result['money'] ?? 0,
        ];
    }

    // ─── 工具方法 ─────────────────────────────────────────────────

    /**
     * KPay EPay V1 签名：去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，
     * 直接接上密钥（中间没有 &），取 MD5 小写。
     */
    public static function sign(array $params, string $key): string
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

    public static function verify(array $data, string $pid, string $key): bool
    {
        $pid = trim($pid);
        $key = trim($key);
        $sign = $data['sign'] ?? null;
        if ($pid === '' || $key === '' || !is_string($sign)) {
            return false;
        }
        if ((string)($data['pid'] ?? '') !== $pid) {
            return false;
        }
        return hash_equals(self::sign($data, $key), strtolower(trim($sign)));
    }

    public static function gateway($url): string
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://[^/\s]+#i', $url)) {
            return self::DEFAULT_GATEWAY;
        }
        return preg_replace('#/epay$#i', '', rtrim($url, '/')) . '/';
    }

    public static function payType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'alipay' => 'alipay',
            'wxpay', 'wechat' => 'wxpay',
            'unionpay', 'bank' => 'unionpay',
            default => '',
        };
    }

    public static function device(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua !== '' && preg_match('/Mobile|Android|iPhone|iPad|iPod|MicroMessenger|AlipayClient|HarmonyOS/i', $ua) ? 'mobile' : 'pc';
    }

    /**
     * 原始 GET 参数；伪静态把路由放在 s 参数里时去掉它（KPay 的通知不会带 s）
     */
    public static function rawQuery(): array
    {
        $data = [];
        parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $data);
        unset($data['s']);
        return $data;
    }

    public static function hmacHeaders(string $apiKey, string $apiSecret, string $method, string $requestUri, string $body): array
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);
        $canonical = implode("\n", [strtoupper($method), $requestUri, $timestamp, $nonce, $bodyHash]);
        return [
            'X-API-Key: ' . $apiKey,
            'X-KPay-Timestamp: ' . $timestamp,
            'X-KPay-Nonce: ' . $nonce,
            'X-KPay-Body-SHA256: ' . $bodyHash,
            'X-KPay-Signature-Method: HMAC-SHA256',
            'X-KPay-Signature: ' . hash_hmac('sha256', $canonical, $apiSecret),
        ];
    }

    protected function postForm(string $url, array $params): array
    {
        return $this->request('POST', $url, http_build_query($params), ['Content-Type: application/x-www-form-urlencoded']);
    }

    protected function request(string $method, string $url, ?string $body, array $headers): array
    {
        $headers[] = 'Accept: application/json';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \RuntimeException($error);
        }
        $result = json_decode((string)$response, true);
        if (!is_array($result)) {
            throw new \RuntimeException('返回数据无法解析');
        }
        return $result;
    }
}
