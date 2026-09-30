<?php
/**
 * KPay 签名与请求（不依赖 WordPress，便于测试）
 */
class KPay_WC_Client
{
    const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

    public static function normalizeGateway($url)
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

    public static function payType($type)
    {
        switch (strtolower(trim((string)$type))) {
            case 'alipay':
                return 'alipay';
            case 'wxpay':
            case 'wechat':
                return 'wxpay';
            case 'unionpay':
                return 'unionpay';
            default:
                return '';
        }
    }

    /**
     * 同一订单可能多次发起支付，每次用新的商户订单号：订单ID + T + 时间
     */
    public static function outTradeNo($orderId, $time = null)
    {
        return (int)$orderId . 'T' . ($time !== null ? $time : time());
    }

    public static function orderIdFromOutTradeNo($outTradeNo)
    {
        return preg_match('/^(\d+)T\d+$/', (string)$outTradeNo, $m) ? (int)$m[1] : 0;
    }

    public static function device($userAgent)
    {
        return $userAgent !== '' && preg_match('/Mobile|Android|iPhone|iPad|iPod|MicroMessenger|AlipayClient|HarmonyOS/i', $userAgent) ? 'mobile' : 'pc';
    }

    public static function hmacHeaders($apiKey, $apiSecret, $method, $requestUri, $body, $timestamp = null, $nonce = null)
    {
        $timestamp = $timestamp !== null ? (string)$timestamp : (string)time();
        $nonce = $nonce !== null ? $nonce : bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', (string)$body);
        $canonical = implode("\n", [strtoupper($method), $requestUri, $timestamp, $nonce, $bodyHash]);
        return [
            'X-API-Key' => $apiKey,
            'X-KPay-Timestamp' => $timestamp,
            'X-KPay-Nonce' => $nonce,
            'X-KPay-Body-SHA256' => $bodyHash,
            'X-KPay-Signature-Method' => 'HMAC-SHA256',
            'X-KPay-Signature' => hash_hmac('sha256', $canonical, $apiSecret),
        ];
    }
}
