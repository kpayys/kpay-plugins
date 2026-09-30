<?php
/**
 * 商户中心：KPay 商户进件
 */
include("../includes/common.php");
if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
require_once PLUGIN_ROOT.'kpay/inc/KpayAjax.php';
require_once PLUGIN_ROOT.'kpay/inc/views.php';
KpayService::instance()->install();
$title='商户进件';
include './head.php';
?>
 <div id="content" class="app-content" role="main">
    <div class="app-content-body ">
<div class="bg-light lter b-b wrapper-md hidden-print">
  <h1 class="m-n font-thin h3">商户进件</h1>
</div>
<div class="wrapper-md control">
<?php kpay_render_apply_form(); ?>
</div>
    </div>
  </div>
<?php include 'foot.php';?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<?php kpay_render_apply_script('./ajax_kpay.php', kpay_csrf_token(), 0); ?>
