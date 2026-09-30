<?php
declare (strict_types=1);

return [
    [
        'title' => '接口地址',
        'name' => 'url',
        'type' => 'input',
        'default' => 'https://api.kaipay.cn',
        'placeholder' => 'https://api.kaipay.cn',
        'tips' => '一般不用改。KPay 提供了其他接入地址时再替换，结尾不要带 /',
        'required' => true,
    ],
    [
        'title' => '商户ID',
        'name' => 'pid',
        'type' => 'input',
        'placeholder' => 'KPay 后台「EPay 配置」里的商户ID（纯数字）',
        'required' => true,
    ],
    [
        'title' => '商户密钥',
        'name' => 'key',
        'type' => 'password',
        'placeholder' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key',
        'required' => true,
    ],
    [
        'title' => '下单方式',
        'name' => 'mode',
        'type' => 'select',
        'default' => 'mapi',
        'dict' => [
            ['id' => 'mapi', 'name' => '服务端下单后跳转（推荐）'],
            ['id' => 'submit', 'name' => '浏览器表单提交'],
        ],
        'tips' => '服务端下单能在下单失败时直接提示原因；服务器访问不了外网时再改成浏览器表单提交',
    ],
    [
        'title' => '订单标题',
        'name' => 'subject',
        'type' => 'input',
        'placeholder' => '留空则使用「订单 + 订单号」',
        'tips' => '会显示在付款页和付款人的账单里，可用 {tradeNo} 代表订单号',
    ],
];
