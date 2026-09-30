<?php
/**
 * Plugin Name: KPay for WooCommerce
 * Plugin URI: https://github.com/kpayys/kpay-plugins
 * Description: 用 KPay 凯付收款，支持支付宝、微信支付、云闪付。
 * Version: 1.0.0
 * Author: KPay
 * Author URI: https://kaipay.cn
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.3
 * License: MIT
 * Text Domain: kpay-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KPAY_WC_VERSION', '1.0.0');
define('KPAY_WC_FILE', __FILE__);

require_once __DIR__ . '/includes/class-kpay-wc-client.php';

// 声明兼容 HPOS（高性能订单存储）和区块结账
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', KPAY_WC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', KPAY_WC_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__ . '/includes/class-wc-gateway-kpay.php';
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_KPay';
        return $gateways;
    });
});

add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once __DIR__ . '/includes/class-kpay-wc-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new KPay_WC_Blocks());
    });
});

add_filter('plugin_action_links_' . plugin_basename(KPAY_WC_FILE), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=kpay');
    array_unshift($links, '<a href="' . esc_url($url) . '">设置</a>');
    return $links;
});
