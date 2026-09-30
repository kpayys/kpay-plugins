<?php
/**
 * 商户中心：KPay 商户进件接口，只能操作自己的进件单
 */
include("../includes/common.php");
if($islogin2==1){}else exit('{"code":-3,"msg":"No Login"}');
if(!checkRefererHost())exit('{"code":403}');
require_once PLUGIN_ROOT.'kpay/inc/KpayAjax.php';

$act = isset($_GET['act']) ? (string)$_GET['act'] : '';
kpay_ajax_run(function () use ($act, $uid) {
	$service = KpayService::instance();
	$service->install();
	return kpay_apply_action($service, $act, $uid, false);
});
