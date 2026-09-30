<?php
/**
 * KPay PHP SDK（单文件，无依赖，PHP 7.1+）
 *
 *   require 'KPay.php';
 *   $kpay = new \KPay\Client('1001', 'EPay Key');
 *   $url  = $kpay->payUrl(['out_trade_no' => 'A1', 'name' => '会员', 'money' => '9.90',
 *                          'notify_url' => 'https://你的域名/notify', 'return_url' => 'https://你的域名/done']);
 *   // 异步通知：$kpay->verifyNotify($_GET) 为 true 且 trade_status=TRADE_SUCCESS 时入账，然后 echo 'success'
 */

namespace KPay;

class Client
{
    const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

    private $pid;
    private $key;
    private $gateway;
    private $apiKey;
    private $apiSecret;

    /**
     * @param string $pid       商户ID（KPay「EPay 接入 → EPay 配置」）
     * @param string $key       EPay Key（「API 密钥」页创建 EPay 兼容密钥时显示）
     * @param string $gateway   接口地址，默认 https://api.kaipay.cn/
     * @param string $apiKey    选填，平台 API 密钥（退款用）
     * @param string $apiSecret 选填，平台 API 密钥的 Secret
     */
    public function __construct($pid, $key, $gateway = '', $apiKey = '', $apiSecret = '')
    {
        $this->pid = trim((string)$pid);
        $this->key = trim((string)$key);
        $this->gateway = self::normalizeGateway($gateway);
        $this->apiKey = trim((string)$apiKey);
        $this->apiSecret = trim((string)$apiSecret);
    }

    /**
     * 浏览器跳转下单的地址（GET /epay/submit）。$order 需要 out_trade_no、name、money、notify_url，
     * 可选 return_url、type（alipay / wxpay / unionpay，不传由付款人在收银台选）、device（pc / mobile）
     */
    public function payUrl(array $order)
    {
        return $this->gateway . 'epay/submit?' . http_build_query($this->signOrder($order));
    }

    /**
     * 服务端下单（POST /epay/mapi），成功返回 KPay 的响应数组，其中 payurl 是付款页地址
     */
    public function createOrder(array $order)
    {
        $result = $this->request('POST', $this->gateway . 'epay/mapi', http_build_query($this->signOrder($order)), ['Content-Type: application/x-www-form-urlencoded']);
        if ((int)($result['code'] ?? 0) !== 1) {
            throw new Exception(!empty($result['msg']) ? $result['msg'] : '下单失败', $result);
        }
        return $result;
    }

    /**
     * 查询订单：传 KPay 订单号 trade_no 或你的订单号 out_trade_no
     */
    public function queryOrder($tradeNo = '', $outTradeNo = '')
    {
        $query = ['act' => 'order', 'pid' => $this->pid, 'key' => $this->key];
        if ($tradeNo !== '') {
            $query['trade_no'] = $tradeNo;
        } else {
            $query['out_trade_no'] = $outTradeNo;
        }
        $result = $this->request('GET', $this->gateway . 'epay/api?' . http_build_query($query), null, []);
        if ((int)($result['code'] ?? 0) !== 1) {
            throw new Exception(!empty($result['msg']) ? $result['msg'] : '查询失败', $result);
        }
        return $result;
    }

    /**
     * 校验异步通知 / 同步跳转带回的参数（签名 + 商户ID）。入账前还要核对 money 和你的订单金额
     */
    public function verifyNotify(array $data)
    {
        if ($this->pid === '' || $this->key === '' || !isset($data['sign']) || !is_string($data['sign'])) {
            return false;
        }
        if (!isset($data['pid']) || (string)$data['pid'] !== $this->pid) {
            return false;
        }
        return hash_equals(self::sign($data, $this->key), strtolower(trim($data['sign'])));
    }

    /**
     * 退款（平台 API，需要勾选「发起退款」权限的平台 API 密钥）。
     * 同一笔退款重试时 $refundRequestNo 保持不变
     */
    public function refund($tradeNo, $amount, $refundRequestNo, $reason = '')
    {
        $path = '/pay/api/order/refund';
        $body = json_encode([
            'orderNo' => (string)$tradeNo,
            'refundAmount' => round((float)$amount, 2),
            'refundRequestNo' => (string)$refundRequestNo,
            'reason' => (string)$reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->request('POST', rtrim($this->gateway, '/') . $path, $body,
            array_merge(['Content-Type: application/json'], $this->hmacHeaders('POST', $path, $body)));
        if ((int)($result['code'] ?? -1) !== 0) {
            throw new Exception(!empty($result['msg']) ? $result['msg'] : '退款失败', $result);
        }
        return $result;
    }

    /**
     * EPay V1 签名：去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，直接接上密钥，取 MD5 小写
     */
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

    /**
     * 平台 API 的 HMAC 请求头。签名原文：METHOD \n PATH?QUERY \n 时间戳 \n nonce \n SHA256(请求体)
     */
    public function hmacHeaders($method, $requestUri, $body, $timestamp = null, $nonce = null)
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new Exception('未配置平台 API 密钥');
        }
        $timestamp = $timestamp !== null ? (string)$timestamp : (string)time();
        $nonce = $nonce !== null ? $nonce : bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', (string)$body);
        $canonical = implode("\n", [strtoupper($method), $requestUri, $timestamp, $nonce, $bodyHash]);
        return [
            'X-API-Key: ' . $this->apiKey,
            'X-KPay-Timestamp: ' . $timestamp,
            'X-KPay-Nonce: ' . $nonce,
            'X-KPay-Body-SHA256: ' . $bodyHash,
            'X-KPay-Signature-Method: HMAC-SHA256',
            'X-KPay-Signature: ' . hash_hmac('sha256', $canonical, $this->apiSecret),
        ];
    }

    public static function normalizeGateway($url)
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://[^/\s]+#i', $url)) {
            return self::DEFAULT_GATEWAY;
        }
        return preg_replace('#/epay$#i', '', rtrim($url, '/')) . '/';
    }

    public function signOrder(array $order)
    {
        if ($this->pid === '' || $this->key === '') {
            throw new Exception('商户ID或 EPay Key 未配置');
        }
        foreach (['out_trade_no', 'name', 'money', 'notify_url'] as $field) {
            if (!isset($order[$field]) || trim((string)$order[$field]) === '') {
                throw new Exception('缺少参数 ' . $field);
            }
        }
        $params = ['pid' => $this->pid];
        foreach (['type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'device'] as $field) {
            if (isset($order[$field]) && trim((string)$order[$field]) !== '') {
                $params[$field] = (string)$order[$field];
            }
        }
        $params['money'] = number_format((float)$params['money'], 2, '.', '');
        $params['sign'] = self::sign($params, $this->key);
        $params['sign_type'] = 'MD5';
        return $params;
    }

    protected function request($method, $url, $body, array $headers)
    {
        $headers[] = 'Accept: application/json';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
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
}

class Exception extends \Exception
{
    /** @var array KPay 的原始响应 */
    public $response;

    public function __construct($message, array $response = [])
    {
        parent::__construct($message);
        $this->response = $response;
    }
}
