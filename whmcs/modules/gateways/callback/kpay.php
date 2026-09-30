<?php
/**
 * WHMCS × KPay 异步通知
 *
 * KPay 以 GET 方式通知到 https://你的WHMCS/modules/gateways/callback/kpay.php
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../kpay/KpayGateway.php';

$gatewayModuleName = 'kpay';
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    die('Module Not Activated');
}

$data = $_GET;
$gateway = new KpayGateway($gatewayParams);
$parsed = $gateway->parseNotify($data);
if ($parsed === null) {
    logTransaction($gatewayParams['name'], $data, '验签失败或状态不对');
    die('fail');
}
list($invoiceId, $tradeNo, $money) = $parsed;

// 同一笔 KPay 订单重复通知：已经入过账，回 success 让 KPay 停止重试
if (\WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $tradeNo)->exists()) {
    die('success');
}
// 账单不存在会直接退出
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($tradeNo);

// 开启了「Convert To For Processing」时，KPay 收的是换算后的人民币，按账单原币种全额入账
$paymentAmount = !empty($gatewayParams['convertto']) ? 0 : $money;

addInvoicePayment($invoiceId, $tradeNo, $paymentAmount, 0, $gatewayModuleName);
logTransaction($gatewayParams['name'], $data, 'Success');
echo 'success';
