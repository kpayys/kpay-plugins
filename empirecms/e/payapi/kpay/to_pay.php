<?php
/**
 * 帝国CMS × KPay：发起支付（由 e/payapi/pay.php 引入，变量 $payr、$money、$ddno、$productname、$PayReturnUrlQz 由它准备）
 */
if (!defined('InEmpireCMS')) {
	exit();
}
require_once dirname(__FILE__) . '/kpay_lib.php';

$kpay_pid = trim($payr['payuser']);
$kpay_key = trim($payr['paykey']);
if ($kpay_pid === '' || $kpay_key === '') {
	printerror('KPay 商户ID或密钥未配置', '', 1, 0, 1);
}

$out_trade_no = kpay_out_trade_no($ddno);
// 跳回时核对订单号，防止别人的跳转链接被拿来给自己入账
esetcookie('checkpaysession', $out_trade_no, 0);

$kpay_params = array(
	'pid' => $kpay_pid,
	'out_trade_no' => $out_trade_no,
	'notify_url' => $PayReturnUrlQz . 'e/payapi/kpay/notify.php',
	'return_url' => $PayReturnUrlQz . 'e/payapi/kpay/payend.php',
	'name' => mb_substr(kpay_to_utf8($productname), 0, 64, 'UTF-8'),
	'money' => number_format((float)$money, 2, '.', ''),
	'device' => preg_match('/Mobile|Android|iPhone|iPad|iPod|MicroMessenger|AlipayClient|HarmonyOS/i', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') ? 'mobile' : 'pc',
);
$kpay_params['sign'] = kpay_sign($kpay_params, $kpay_key);
$kpay_params['sign_type'] = 'MD5';

header('Location: ' . kpay_gateway($payr['payemail']) . 'epay/submit?' . http_build_query($kpay_params));
exit();
