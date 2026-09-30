<?php

namespace payment;

/**
 * ShopXO × KPay 支付插件
 *
 * 放到 extend/payment/ 下，文件名和类名都是 Kpay。
 */
class Kpay
{
    const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

    private $config;

    public function __construct($params = [])
    {
        $this->config = is_array($params) ? $params : [];
    }

    public function Config()
    {
        $base = [
            'name' => 'KPay 凯付',
            'version' => '1.0.0',
            'apply_version' => '不限',
            'apply_terminal' => ['pc', 'h5', 'weixin', 'alipay'],
            'desc' => '通过 KPay 收款，支持支付宝、微信支付、云闪付和原路退款。<a href="https://github.com/kpayys/kpay-plugins/tree/main/shopxo" target="_blank">使用说明</a>',
            'author' => 'KPay',
            'author_url' => 'https://kaipay.cn',
        ];
        $element = [
            [
                'element' => 'input',
                'type' => 'text',
                'default' => self::DEFAULT_GATEWAY,
                'name' => 'api_url',
                'placeholder' => '接口地址',
                'title' => '接口地址',
                'is_required' => 0,
                'message' => '一般填 https://api.kaipay.cn/',
            ],
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'pid',
                'placeholder' => 'KPay「EPay 接入 → EPay 配置」里的商户ID',
                'title' => '商户ID',
                'is_required' => 0,
                'message' => '请填写 KPay 商户ID',
            ],
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'key',
                'placeholder' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key',
                'title' => '商户密钥',
                'is_required' => 0,
                'message' => '请填写 EPay Key',
            ],
            [
                'element' => 'select',
                'title' => '支付方式',
                'message' => '请选择支付方式',
                'name' => 'pay_type',
                'is_multiple' => 0,
                'element_data' => [
                    ['value' => 'cashier', 'name' => 'KPay 收银台（付款人自选）'],
                    ['value' => 'alipay', 'name' => '支付宝'],
                    ['value' => 'wxpay', 'name' => '微信支付'],
                    ['value' => 'unionpay', 'name' => '云闪付'],
                ],
            ],
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'refund_api_key',
                'placeholder' => '选填，平台 API 密钥的 API Key（勾选「发起退款」权限）',
                'title' => '退款 API Key',
                'is_required' => 0,
                'message' => '',
            ],
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'refund_api_secret',
                'placeholder' => '选填，与上面的 API Key 配对的 Secret',
                'title' => '退款 API Secret',
                'is_required' => 0,
                'message' => '',
            ],
        ];
        return ['base' => $base, 'element' => $element];
    }

    /**
     * 发起支付：服务端向 KPay 下单，返回付款页地址，由 ShopXO 跳转
     */
    public function Pay($params = [])
    {
        if (empty($params)) {
            return DataReturn('参数不能为空', -1);
        }
        $pid = $this->value('pid');
        $key = $this->value('key');
        if ($pid === '' || $key === '') {
            return DataReturn('KPay 商户ID或密钥未配置', -1);
        }

        $data = [
            'pid' => $pid,
            'out_trade_no' => (string)$params['order_no'],
            'notify_url' => (string)$params['notify_url'],
            'return_url' => (string)$params['call_back_url'],
            'name' => mb_substr((string)(isset($params['name']) && $params['name'] !== '' ? $params['name'] : '订单 ' . $params['order_no']), 0, 64),
            'money' => number_format((float)$params['total_price'], 2, '.', ''),
            'device' => self::device(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : ''),
        ];
        $type = self::payType($this->value('pay_type'));
        if ($type !== '') {
            $data['type'] = $type;
        }
        $data['sign'] = self::sign($data, $key);
        $data['sign_type'] = 'MD5';

        try {
            $result = $this->request('POST', $this->gateway() . 'epay/mapi', http_build_query($data), ['Content-Type: application/x-www-form-urlencoded']);
        } catch (\Exception $e) {
            return DataReturn('支付网关连接失败，请稍后重试', -1);
        }
        if ((int)($result['code'] ?? 0) !== 1) {
            return DataReturn(!empty($result['msg']) ? $result['msg'] : '下单失败，请稍后重试', -1);
        }
        foreach (['payurl', 'qrcode'] as $field) {
            $url = trim((string)($result[$field] ?? ''));
            if (preg_match('#^https?://#i', $url)) {
                return DataReturn('success', 0, $url);
            }
        }
        return DataReturn('支付网关未返回付款地址', -1);
    }

    /**
     * 异步通知和同步跳转都会进这里。KPay 以 GET 方式带签名参数，
     * ShopXO 的入口文件把路由放在 s 参数里，它不参与签名
     */
    public function Respond($params = [])
    {
        $data = self::rawQuery();
        if (empty($data)) {
            $data = is_array($params) ? $params : [];
            unset($data['s']);
        }
        if (!self::verify($data, $this->value('pid'), $this->value('key'))) {
            return DataReturn('签名校验失败', -1);
        }
        if (($data['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return DataReturn('支付未完成', -1);
        }
        return DataReturn('支付成功', 0, [
            'trade_no' => (string)$data['trade_no'],
            'buyer_user' => '',
            'out_trade_no' => (string)$data['out_trade_no'],
            'subject' => (string)($data['name'] ?? ''),
            'pay_price' => (string)$data['money'],
        ]);
    }

    public function Refund($params = [])
    {
        foreach (['order_no' => '订单号不能为空', 'trade_no' => '交易平台订单号不能为空', 'refund_price' => '退款金额不能为空'] as $field => $message) {
            if (empty($params[$field])) {
                return DataReturn($message, -1);
            }
        }
        $apiKey = $this->value('refund_api_key');
        $apiSecret = $this->value('refund_api_secret');
        if ($apiKey === '' || $apiSecret === '') {
            return DataReturn('未配置退款 API Key，请到 KPay 商户后台操作退款', -1);
        }

        $path = '/pay/api/order/refund';
        $requestNo = $params['order_no'] . 'R' . str_replace('.', '', (string)$params['refund_price']);
        $body = json_encode([
            'orderNo' => (string)$params['trade_no'],
            'refundAmount' => round((float)$params['refund_price'], 2),
            'refundRequestNo' => $requestNo,
            'reason' => empty($params['refund_reason']) ? '订单退款' : mb_substr((string)$params['refund_reason'], 0, 100),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $result = $this->request('POST', rtrim($this->gateway(), '/') . $path, $body,
                array_merge(['Content-Type: application/json'], self::hmacHeaders($apiKey, $apiSecret, 'POST', $path, $body)));
        } catch (\Exception $e) {
            return DataReturn('连接 KPay 失败：' . $e->getMessage(), -1);
        }
        if ((int)($result['code'] ?? -1) !== 0) {
            return DataReturn(!empty($result['msg']) ? $result['msg'] : '退款失败', -1);
        }
        return DataReturn('退款成功', 0, [
            'out_trade_no' => (string)$params['order_no'],
            'trade_no' => (string)$params['trade_no'],
            'buyer_user' => '',
            'refund_price' => $params['refund_price'],
            'return_params' => $result,
            'request_params' => ['refundRequestNo' => $requestNo],
        ]);
    }

    // ─── 工具方法 ─────────────────────────────────────────────────

    private function value($key)
    {
        return isset($this->config[$key]) ? trim((string)$this->config[$key]) : '';
    }

    private function gateway()
    {
        $url = $this->value('api_url');
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

    public static function payType($type)
    {
        switch (strtolower(trim((string)$type))) {
            case 'alipay':
                return 'alipay';
            case 'wxpay':
                return 'wxpay';
            case 'unionpay':
                return 'unionpay';
            default:
                return '';
        }
    }

    public static function device($userAgent)
    {
        return $userAgent !== '' && preg_match('/Mobile|Android|iPhone|iPad|iPod|MicroMessenger|AlipayClient|HarmonyOS/i', $userAgent) ? 'mobile' : 'pc';
    }

    /**
     * 原始 GET 参数，不经过框架过滤器；去掉 ShopXO 入口文件的路由参数 s
     */
    public static function rawQuery()
    {
        $data = [];
        parse_str(isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '', $data);
        unset($data['s']);
        return $data;
    }

    public static function hmacHeaders($apiKey, $apiSecret, $method, $requestUri, $body)
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', (string)$body);
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

    protected function request($method, $url, $body, array $headers)
    {
        $headers[] = 'Accept: application/json';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \Exception($error);
        }
        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new \Exception('KPay 返回数据无法解析');
        }
        return $result;
    }
}
