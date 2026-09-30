<?php
/**
 * KPay 插件后台配置
 *
 * 智简魔方 V10 约定：键名小写加下划线；每项都要有 value；枚举用 radio，options 写成 [提交值 => 显示文案]；
 * return_url、notify_url 是系统保留键，不能用作配置项。
 */
return [
    'module_name' => [
        'title' => '通道名称',
        'type' => 'text',
        'value' => 'KPay 凯付',
        'tip' => '显示在后台支付接口列表和前台收银台',
        'size' => 200,
    ],
    'apiurl' => [
        'title' => '接口地址',
        'type' => 'text',
        'value' => 'https://api.kaipay.cn/',
        'tip' => '一般不用改',
        'size' => 200,
    ],
    'pid' => [
        'title' => '商户ID',
        'type' => 'text',
        'value' => '',
        'tip' => 'KPay 商户后台「EPay 接入 → EPay 配置」里的商户ID',
        'size' => 200,
    ],
    'key' => [
        'title' => '商户密钥',
        'type' => 'password',
        'value' => '',
        'tip' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key',
        'size' => 200,
    ],
    'pay_type' => [
        'title' => '支付方式',
        'type' => 'radio',
        'value' => 'cashier',
        'options' => [
            'cashier' => 'KPay 收银台（付款人自选）',
            'alipay' => '支付宝',
            'wxpay' => '微信支付',
            'unionpay' => '云闪付',
        ],
    ],
    'refund_api_key' => [
        'title' => '退款 API Key',
        'type' => 'text',
        'value' => '',
        'tip' => '选填。KPay「API 密钥」里创建的平台 API 密钥（勾选「发起退款」权限），填了才能原路退款',
        'size' => 200,
    ],
    'refund_api_secret' => [
        'title' => '退款 API Secret',
        'type' => 'password',
        'value' => '',
        'tip' => '选填。与上面的 API Key 配对',
        'size' => 200,
    ],
];
