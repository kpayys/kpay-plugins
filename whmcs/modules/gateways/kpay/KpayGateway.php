<?php
/**
 * KPay 接口的最小实现（WHMCS 模块与回调共用，不依赖 WHMCS 函数，便于测试）
 */
class KpayGateway
{
    const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

    private $gateway;
    private $pid;
    private $key;
    private $payType;
    private $subject;
    private $refundApiKey;
    private $refundApiSecret;

    public function __construct(array $params)
    {
        $this->gateway = self::normalizeGateway(isset($params['apiUrl']) ? $params['apiUrl'] : '');
        $this->pid = trim((string)(isset($params['pid']) ? $params['pid'] : ''));
        $this->key = trim((string)(isset($params['key']) ? $params['key'] : ''));
        $this->payType = (string)(isset($params['payType']) ? $params['payType'] : 'cashier');
        $this->subject = trim((string)(isset($params['subject']) ? $params['subject'] : ''));
        $this->refundApiKey = trim((string)(isset($params['refundApiKey']) ? $params['refundApiKey'] : ''));
        $this->refundApiSecret = trim((string)(isset($params['refundApiSecret']) ? $params['refundApiSecret'] : ''));
    }

    public static function normalizeGateway($url)
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://[^/\s]+#i', $url)) {
            return self::DEFAULT_GATEWAY;
        }
        return preg_replace('#/epay$#i', '', rtrim($url, '/')) . '/';
    }

    /**
     * 同一张账单可能多次点支付（改价、订单过期），每次用新的商户订单号：账单号 + T + 时间
     */
    public static function outTradeNo($invoiceId, $time = null)
    {
        return (int)$invoiceId . 'T' . ($time !== null ? $time : time());
    }

    public static function invoiceIdFromOutTradeNo($outTradeNo)
    {
        return preg_match('/^(\d+)T\d+$/', (string)$outTradeNo, $m) ? (int)$m[1] : 0;
    }

    public function orderParams($invoiceId, $amount, $notifyUrl, $returnUrl, $time = null)
    {
        if ($this->pid === '' || $this->key === '') {
            throw new Exception('KPay 商户ID或密钥未配置');
        }
        $subject = $this->subject !== '' ? str_replace('{invoiceid}', (string)$invoiceId, $this->subject) : '账单 ' . $invoiceId;
        $params = [
            'pid' => $this->pid,
            'out_trade_no' => self::outTradeNo($invoiceId, $time),
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'name' => function_exists('mb_substr') ? mb_substr($subject, 0, 64) : substr($subject, 0, 64),
            'money' => number_format((float)$amount, 2, '.', ''),
        ];
        $type = self::payType($this->payType);
        if ($type !== '') {
            $params['type'] = $type;
        }
        $params['sign'] = self::sign($params, $this->key);
        $params['sign_type'] = 'MD5';
        return $params;
    }

    public function buildPayForm($invoiceId, $amount, $notifyUrl, $returnUrl, $buttonText)
    {
        $params = $this->orderParams($invoiceId, $amount, $notifyUrl, $returnUrl);
        $html = '<form method="post" action="' . self::esc($this->gateway . 'epay/submit') . '">';
        foreach ($params as $name => $value) {
            $html .= '<input type="hidden" name="' . self::esc($name) . '" value="' . self::esc($value) . '"/>';
        }
        $html .= '<input type="submit" class="btn btn-primary" value="' . self::esc($buttonText !== '' ? $buttonText : '立即支付') . '"/></form>';
        return $html;
    }

    /**
     * 校验异步通知，返回 [账单号, KPay 订单号, 金额]；不合法时返回 null
     */
    public function parseNotify(array $data)
    {
        if (!self::verify($data, $this->pid, $this->key)) {
            return null;
        }
        if (!isset($data['trade_status']) || $data['trade_status'] !== 'TRADE_SUCCESS') {
            return null;
        }
        $invoiceId = self::invoiceIdFromOutTradeNo(isset($data['out_trade_no']) ? $data['out_trade_no'] : '');
        if ($invoiceId <= 0 || empty($data['trade_no']) || !isset($data['money'])) {
            return null;
        }
        return [$invoiceId, (string)$data['trade_no'], (string)$data['money']];
    }

    public function refund($tradeNo, $amount, $requestNo)
    {
        if ($this->refundApiKey === '' || $this->refundApiSecret === '') {
            throw new Exception('未配置退款 API Key，请到 KPay 商户后台操作退款');
        }
        if ($tradeNo === '') {
            throw new Exception('缺少 KPay 订单号');
        }
        $path = '/pay/api/order/refund';
        $body = json_encode([
            'orderNo' => $tradeNo,
            'refundAmount' => round($amount, 2),
            'refundRequestNo' => $requestNo,
            'reason' => 'WHMCS 退款',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->request('POST', rtrim($this->gateway, '/') . $path, $body,
            array_merge(['Content-Type: application/json'], self::hmacHeaders($this->refundApiKey, $this->refundApiSecret, 'POST', $path, $body)));
        if (!isset($result['code']) || (int)$result['code'] !== 0) {
            throw new Exception(!empty($result['msg']) ? $result['msg'] : '退款失败');
        }
        return $result;
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
            case 'bank':
                return 'unionpay';
            default:
                return '';
        }
    }

    public static function hmacHeaders($apiKey, $apiSecret, $method, $requestUri, $body, $timestamp = null, $nonce = null)
    {
        $timestamp = $timestamp !== null ? (string)$timestamp : (string)time();
        $nonce = $nonce !== null ? $nonce : bin2hex(random_bytes(16));
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
            throw new Exception('连接 KPay 失败：' . $error);
        }
        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new Exception('KPay 返回数据无法解析');
        }
        return $result;
    }

    private static function esc($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
