<?php

/**
 * KPay 接口客户端（易支付插件、进件模块共用，兼容 PHP 7.1+）
 *
 * - EPay V1：MD5 签名下单、查单
 * - 开放 API：API Key + HMAC-SHA256 签名的 JSON / multipart 请求
 * - 进件回调验签
 */
class KpayClient
{
	const DEFAULT_GATEWAY = 'https://api.kaipay.cn/';

	private $gateway;
	private $apiKey;
	private $apiSecret;

	public function __construct($gateway = '', $apiKey = '', $apiSecret = '')
	{
		$this->gateway = self::normalizeGateway($gateway);
		$this->apiKey = trim((string)$apiKey);
		$this->apiSecret = trim((string)$apiSecret);
	}

	public function gateway()
	{
		return $this->gateway;
	}

	/**
	 * 接受 https://api.kaipay.cn、https://api.kaipay.cn/、https://api.kaipay.cn/epay/，统一成以 / 结尾的根地址
	 */
	public static function normalizeGateway($url)
	{
		$url = trim((string)$url);
		if ($url === '') {
			return self::DEFAULT_GATEWAY;
		}
		if (!preg_match('#^https?://[^/\s]+#i', $url)) {
			throw new Exception('KPay 接口地址格式不正确');
		}
		$url = rtrim($url, '/');
		$url = preg_replace('#/epay$#i', '', $url);
		return $url . '/';
	}

	// ─── EPay V1 ────────────────────────────────────────────────────

	/**
	 * 去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，直接接上密钥，取 MD5 小写
	 */
	public static function epaySign(array $params, $key)
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
	 * 校验 KPay 发来的 EPay 通知 / 回跳参数，并确认 pid 就是期望的商户
	 */
	public static function epayVerify(array $data, $pid, $key)
	{
		$pid = trim((string)$pid);
		$key = trim((string)$key);
		if ($pid === '' || $key === '' || !isset($data['sign']) || !is_string($data['sign'])) {
			return false;
		}
		if (!isset($data['pid']) || (string)$data['pid'] !== $pid) {
			return false;
		}
		return hash_equals(self::epaySign($data, $key), strtolower(trim($data['sign'])));
	}

	public function epayPost($path, array $params)
	{
		$response = $this->send('POST', $this->gateway . ltrim($path, '/'), http_build_query($params), [
			'Content-Type: application/x-www-form-urlencoded',
		]);
		return self::decode($response['body']);
	}

	/**
	 * 用商户ID和密钥查询商户信息，常用于校验一组 EPay 凭据是否有效
	 */
	public function epayQueryMerchant($pid, $key)
	{
		$url = $this->gateway . 'epay/api?' . http_build_query(['act' => 'query', 'pid' => $pid, 'key' => $key]);
		$response = $this->send('GET', $url, null, []);
		return self::decode($response['body']);
	}

	public function epayQueryOrder($pid, $key, $tradeNo, $outTradeNo = '')
	{
		$query = ['act' => 'order', 'pid' => $pid, 'key' => $key];
		if ($tradeNo !== '') {
			$query['trade_no'] = $tradeNo;
		} else {
			$query['out_trade_no'] = $outTradeNo;
		}
		$response = $this->send('GET', $this->gateway . 'epay/api?' . http_build_query($query), null, []);
		return self::decode($response['body']);
	}

	// ─── 开放 API（HMAC） ───────────────────────────────────────────

	/**
	 * 调用 /pay/api/* 开放接口，返回 data；code 不为 0 时抛出异常
	 *
	 * @param string $method GET / POST / PUT / DELETE
	 * @param string $path   如 /pay/api/onboarding/options
	 * @param array  $query  URL 查询参数
	 * @param array|null $body JSON 请求体
	 */
	public function api($method, $path, array $query = [], $body = null)
	{
		$method = strtoupper($method);
		$requestUri = '/' . ltrim($path, '/');
		if (!empty($query)) {
			$requestUri .= '?' . http_build_query($query);
		}
		$raw = '';
		$headers = [];
		if ($body !== null) {
			$raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$headers[] = 'Content-Type: application/json';
		}
		$headers = array_merge($headers, $this->signHeaders($method, $requestUri, $raw));
		$response = $this->send($method, rtrim($this->gateway, '/') . $requestUri, $body !== null ? $raw : null, $headers);
		return self::unwrap(self::decode($response['body']));
	}

	/**
	 * multipart 上传。HMAC 对最终整个请求体签名，所以先把表单字节固定下来再签名、发送同一份内容
	 *
	 * @param array $fields 普通字段
	 * @param string $fileField 文件字段名
	 * @param string $fileName
	 * @param string $mime
	 * @param string $bytes
	 */
	public function upload($path, array $fields, $fileField, $fileName, $mime, $bytes)
	{
		$boundary = '----KPayBoundary' . bin2hex(random_bytes(12));
		$raw = self::buildMultipart($boundary, $fields, $fileField, $fileName, $mime, $bytes);
		$requestUri = '/' . ltrim($path, '/');
		$headers = array_merge(
			['Content-Type: multipart/form-data; boundary=' . $boundary],
			$this->signHeaders('POST', $requestUri, $raw)
		);
		$response = $this->send('POST', rtrim($this->gateway, '/') . $requestUri, $raw, $headers);
		return self::unwrap(self::decode($response['body']));
	}

	public static function buildMultipart($boundary, array $fields, $fileField, $fileName, $mime, $bytes)
	{
		$eol = "\r\n";
		$raw = '';
		foreach ($fields as $name => $value) {
			$raw .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="' . self::quoteHeader($name) . '"' . $eol . $eol
				. $value . $eol;
		}
		$raw .= '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="' . self::quoteHeader($fileField) . '"; filename="' . self::quoteHeader($fileName) . '"' . $eol
			. 'Content-Type: ' . $mime . $eol . $eol
			. $bytes . $eol
			. '--' . $boundary . '--' . $eol;
		return $raw;
	}

	/**
	 * 签名原文：METHOD \n PATH?QUERY \n 时间戳 \n nonce \n SHA256(请求体)
	 */
	public static function canonical($method, $requestUri, $timestamp, $nonce, $bodyHash)
	{
		return implode("\n", [strtoupper($method), $requestUri, $timestamp, $nonce, strtolower($bodyHash)]);
	}

	public function signHeaders($method, $requestUri, $raw, $timestamp = null, $nonce = null)
	{
		if ($this->apiKey === '' || $this->apiSecret === '') {
			throw new Exception('未配置 KPay API Key');
		}
		$timestamp = $timestamp !== null ? (string)$timestamp : (string)time();
		$nonce = $nonce !== null ? $nonce : bin2hex(random_bytes(16));
		$bodyHash = hash('sha256', (string)$raw);
		$signature = hash_hmac('sha256', self::canonical($method, $requestUri, $timestamp, $nonce, $bodyHash), $this->apiSecret);
		return [
			'X-API-Key: ' . $this->apiKey,
			'X-KPay-Timestamp: ' . $timestamp,
			'X-KPay-Nonce: ' . $nonce,
			'X-KPay-Body-SHA256: ' . $bodyHash,
			'X-KPay-Signature-Method: HMAC-SHA256',
			'X-KPay-Signature: ' . $signature,
		];
	}

	// ─── 进件回调 ───────────────────────────────────────────────────

	/**
	 * 进件状态回调验签：HMAC-SHA256(callbackSecret, 时间戳 \n nonce \n event \n SHA256(请求体))
	 *
	 * @param array $headers 小写键名的请求头
	 */
	public static function verifyCallback($secret, array $headers, $rawBody, $maxSkew = 600, $now = null)
	{
		$secret = trim((string)$secret);
		$timestamp = isset($headers['x-kpay-timestamp']) ? trim($headers['x-kpay-timestamp']) : '';
		$nonce = isset($headers['x-kpay-nonce']) ? trim($headers['x-kpay-nonce']) : '';
		$event = isset($headers['x-kpay-event']) ? trim($headers['x-kpay-event']) : '';
		$signature = isset($headers['x-kpay-signature']) ? strtolower(trim($headers['x-kpay-signature'])) : '';
		if ($secret === '' || $timestamp === '' || $nonce === '' || $signature === '' || !ctype_digit($timestamp)) {
			return false;
		}
		$now = $now !== null ? $now : time();
		if (abs($now - (int)$timestamp) > $maxSkew) {
			return false;
		}
		$bodyHash = hash('sha256', (string)$rawBody);
		if (isset($headers['x-kpay-body-sha256']) && strtolower(trim($headers['x-kpay-body-sha256'])) !== $bodyHash) {
			return false;
		}
		$expected = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, $event, $bodyHash]), $secret);
		return hash_equals($expected, $signature);
	}

	/**
	 * 从 $_SERVER 取出小写键名的请求头
	 */
	public static function requestHeaders()
	{
		$headers = [];
		foreach ($_SERVER as $name => $value) {
			if (strpos($name, 'HTTP_') === 0) {
				$headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string)$value;
			}
		}
		return $headers;
	}

	// ─── 底层 ───────────────────────────────────────────────────────

	private static function quoteHeader($value)
	{
		return str_replace(['"', "\r", "\n"], ['%22', '', ''], (string)$value);
	}

	private static function decode($body)
	{
		$result = json_decode((string)$body, true);
		if (!is_array($result)) {
			throw new Exception('KPay 返回数据无法解析');
		}
		return $result;
	}

	private static function unwrap(array $result)
	{
		if (!isset($result['code']) || (int)$result['code'] !== 0) {
			throw new Exception(!empty($result['msg']) ? $result['msg'] : 'KPay 接口调用失败');
		}
		return isset($result['data']) ? $result['data'] : [];
	}

	protected function send($method, $url, $body, array $headers)
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
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false) {
			throw new Exception('连接 KPay 失败：' . $error);
		}
		return ['status' => $status, 'body' => $response];
	}
}
