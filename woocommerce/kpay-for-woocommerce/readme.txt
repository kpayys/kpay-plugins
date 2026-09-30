=== KPay for WooCommerce ===
Contributors: kpayys
Tags: woocommerce, payment, alipay, wechat pay, unionpay
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT

用 KPay 凯付收款，支持支付宝、微信支付、云闪付。

== Description ==

* 结账后跳转到 KPay 付款页，手机和电脑自动匹配付款方式
* 支持经典结账和区块结账，兼容高性能订单存储（HPOS）
* 异步通知校验签名、商户ID 和金额，重复通知不会重复入账
* 配置退款 API Key 后可以在订单页直接退款

店铺货币需要是人民币（CNY）。

== Installation ==

1. 把 kpay-for-woocommerce 目录上传到 wp-content/plugins/，或在「插件 → 安装插件 → 上传插件」上传 zip。
2. 启用插件，打开「WooCommerce → 设置 → 付款 → KPay 凯付」。
3. 填写 KPay 商户ID（「EPay 接入 → EPay 配置」）和 EPay Key（「API 密钥」页创建 EPay 兼容密钥时显示），保存并启用。

完整说明见 https://github.com/kpayys/kpay-plugins/tree/main/woocommerce
