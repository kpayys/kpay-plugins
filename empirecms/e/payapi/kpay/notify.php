<?php
/**
 * 帝国CMS × KPay：异步通知
 *
 * 帝国CMS 的订单信息在付款人浏览器的 Cookie 里，服务器之间的通知拿不到，
 * 所以这里只验签并回复已收到，入账在付款人跳回 payend.php 时完成。
 */
require('../../class/connect.php');
require('../../class/db_sql.php');
require_once dirname(__FILE__) . '/kpay_lib.php';
$link = db_connect();
$empire = new mysqlquery();

$payr = $empire->fetch1("select payuser,paykey from {$dbtbpre}enewspayapi where paytype='kpay' limit 1");
$data = kpay_raw_query();
echo kpay_verify($data, $payr['payuser'], $payr['paykey']) ? 'success' : 'fail';
db_close();
$empire = null;
