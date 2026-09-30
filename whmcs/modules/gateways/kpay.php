<?php
/**
 * WHMCS × KPay 支付网关模块
 *
 * 放到 WHMCS 的 modules/gateways/ 下，回调文件放 modules/gateways/callback/。
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/kpay/KpayGateway.php';

function kpay_MetaData()
{
    return [
        'DisplayName' => 'KPay 凯付',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function kpay_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'KPay 凯付',
        ],
        'apiUrl' => [
            'FriendlyName' => '接口地址',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://api.kaipay.cn/',
            'Description' => '一般不用改',
        ],
        'pid' => [
            'FriendlyName' => '商户ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'KPay 商户后台「EPay 接入 → EPay 配置」里的商户ID',
        ],
        'key' => [
            'FriendlyName' => '商户密钥',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key',
        ],
        'payType' => [
            'FriendlyName' => '支付方式',
            'Type' => 'dropdown',
            'Options' => [
                'cashier' => 'KPay 收银台（付款人自选）',
                'alipay' => '支付宝',
                'wxpay' => '微信支付',
                'unionpay' => '云闪付',
            ],
            'Default' => 'cashier',
        ],
        'subject' => [
            'FriendlyName' => '订单标题',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '账单 {invoiceid}',
            'Description' => '付款页和付款人账单上显示的名称，{invoiceid} 会替换成账单号',
        ],
        'refundApiKey' => [
            'FriendlyName' => '退款 API Key',
            'Type' => 'text',
            'Size' => '60',
            'Description' => '选填。KPay「API 密钥」里创建的平台 API 密钥（勾选「发起退款」权限），填了才能在 WHMCS 里退款',
        ],
        'refundApiSecret' => [
            'FriendlyName' => '退款 API Secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => '选填。与上面的 API Key 配对',
        ],
    ];
}

/**
 * 账单页的「立即支付」按钮：表单直接提交到 KPay 付款页
 */
function kpay_link($params)
{
    try {
        $gateway = new KpayGateway($params);
        $form = $gateway->buildPayForm(
            (int)$params['invoiceid'],
            (string)$params['amount'],
            $params['systemurl'] . 'modules/gateways/callback/kpay.php',
            (string)$params['returnurl'],
            (string)$params['langpaynow']
        );
    } catch (Exception $e) {
        return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $form;
}

/**
 * WHMCS 后台退款。需要配置退款 API Key
 */
function kpay_refund($params)
{
    $requestNo = 'R' . $params['transid'] . 'T' . date('YmdHis');
    try {
        $gateway = new KpayGateway($params);
        $result = $gateway->refund((string)$params['transid'], (float)$params['amount'], $requestNo);
    } catch (Exception $e) {
        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }
    return ['status' => 'success', 'rawdata' => $result, 'transid' => $requestNo];
}
