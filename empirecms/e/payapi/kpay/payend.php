<?php
/**
 * 帝国CMS × KPay：付款后跳回，在这里入账
 *
 * 帝国CMS 的充值类型、商城订单、登录用户都存在付款人浏览器的 Cookie 里，
 * 所以入账只能在付款人跳回这一步完成（自带的支付宝接口也是这样）。
 */
require('../../class/connect.php');
require('../../class/db_sql.php');
require('../../class/q_functions.php');
require('../../member/class/user.php');
require_once dirname(__FILE__) . '/kpay_lib.php';
$link = db_connect();
$empire = new mysqlquery();
$editor = 1;

$paytype = 'kpay';
$payr = $empire->fetch1("select * from {$dbtbpre}enewspayapi where paytype='$paytype' limit 1");
$data = kpay_raw_query();

if (!kpay_verify($data, $payr['payuser'], $payr['paykey'])) {
	printerror('支付结果验签失败', '../../../', 1, 0, 1);
}
if (!isset($data['trade_status']) || $data['trade_status'] !== 'TRADE_SUCCESS') {
	printerror('订单尚未支付完成', '../../../', 1, 0, 1);
}
// 只认本浏览器发起的订单
$checkpaysession = getcvar('checkpaysession');
if (!$checkpaysession || (string)$checkpaysession !== (string)$data['out_trade_no']) {
	printerror('订单信息不一致，请在发起支付的浏览器中完成支付', '../../../', 1, 0, 1);
}
esetcookie('checkpaysession', '', 0);

$phome = getcvar('payphome');
if (!in_array($phome, array('PayToFen', 'PayToMoney', 'ShopPay', 'BuyGroupPay'), true)) {
	printerror('您来自的链接不存在', '', 1, 0, 1);
}
$user = array();
if ($phome == 'PayToFen' || $phome == 'PayToMoney' || $phome == 'BuyGroupPay') {
	$user = islogin();
}

include('../payfun.php');
$pr = $empire->fetch1("select paymoneytofen,payminmoney from {$dbtbpre}enewspublic limit 1");
$orderid = $data['trade_no'];    // KPay 订单号，payfun 里据此防止重复入账
$money = (float)$data['money'];
$fen = floor($money) * $pr['paymoneytofen'];

if ($phome == 'PayToFen') {
	$paybz = '购买点数: ' . $fen;
	PayApiBuyFen($fen, $money, $paybz, $orderid, $user['userid'], $user['username'], $paytype);
} elseif ($phome == 'PayToMoney') {
	$paybz = '存预付款';
	PayApiPayMoney($money, $paybz, $orderid, $user['userid'], $user['username'], $paytype);
} elseif ($phome == 'ShopPay') {
	include('../../data/dbcache/class.php');
	$ddid = (int)getcvar('paymoneyddid');
	$paybz = '商城购买 [!--ddno--] 的订单(ddid=' . $ddid . ')';
	PayApiShopPay($ddid, $money, $paybz, $orderid, '', '', $paytype);
} elseif ($phome == 'BuyGroupPay') {
	include('../../data/dbcache/MemberLevel.php');
	$bgid = (int)getcvar('paymoneybgid');
	PayApiBuyGroupPay($bgid, $money, $orderid, $user['userid'], $user['username'], $user['groupid'], $paytype);
}
db_close();
$empire = null;
