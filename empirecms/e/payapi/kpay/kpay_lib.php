<?php
/**
 * 帝国CMS × KPay：签名工具（to_pay.php、payend.php、notify.php 共用）
 */

define('KPAY_DEFAULT_GATEWAY', 'https://api.kaipay.cn/');

/**
 * 接口地址存放在支付接口的「支付宝账号」字段（payemail），留空用默认地址
 */
function kpay_gateway($url)
{
	$url = trim((string)$url);
	if ($url === '' || !preg_match('#^https?://[^/\s]+#i', $url)) {
		return KPAY_DEFAULT_GATEWAY;
	}
	return preg_replace('#/epay$#i', '', rtrim($url, '/')) . '/';
}

/**
 * EPay V1 签名：去掉 sign、sign_type 和空值，按参数名升序拼成 a=1&b=2，直接接上密钥，取 MD5 小写
 */
function kpay_sign(array $params, $key)
{
	ksort($params, SORT_STRING);
	$pairs = array();
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

function kpay_verify(array $data, $pid, $key)
{
	$pid = trim((string)$pid);
	$key = trim((string)$key);
	if ($pid === '' || $key === '' || !isset($data['sign']) || !is_string($data['sign'])) {
		return false;
	}
	if (!isset($data['pid']) || (string)$data['pid'] !== $pid) {
		return false;
	}
	return hash_equals(kpay_sign($data, $key), strtolower(trim($data['sign'])));
}

/**
 * 帝国CMS 在 pay.php 里把商品名转成了 GB2312（为老支付宝接口准备的），KPay 要 UTF-8，转回来
 */
function kpay_to_utf8($text)
{
	$text = (string)$text;
	if ($text === '' || preg_match('//u', $text)) {
		return $text;
	}
	if (function_exists('mb_convert_encoding')) {
		return mb_convert_encoding($text, 'UTF-8', 'GBK');
	}
	return function_exists('iconv') ? (string)iconv('GBK', 'UTF-8//IGNORE', $text) : $text;
}

/**
 * 只取 KPay 发来的参数（GET），不经过帝国CMS 的变量处理
 */
function kpay_raw_query()
{
	$data = array();
	parse_str(isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '', $data);
	return $data;
}

function kpay_out_trade_no($ddno)
{
	$ddno = trim((string)$ddno);
	return $ddno !== '' ? $ddno : 'EC' . date('YmdHis') . mt_rand(100000, 999999);
}
