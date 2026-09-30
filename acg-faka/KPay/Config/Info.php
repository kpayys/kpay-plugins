<?php
declare (strict_types=1);

return [
    'version' => '1.0.0',
    'name' => 'KPay 凯付',
    'author' => 'KPay',
    'website' => 'https://kaipay.cn',
    'description' => '通过 KPay 收款，支持支付宝、微信支付、云闪付。回调自动验签、校验金额。',
    'options' => [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
        'unionpay' => '云闪付',
        'cashier' => 'KPay 收银台（付款人自选）',
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'trade_status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 'TRADE_SUCCESS',
        \App\Consts\Pay::FIELD_ORDER_KEY => 'out_trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'money',
        \App\Consts\Pay::FIELD_RESPONSE => 'success',
    ],
];
