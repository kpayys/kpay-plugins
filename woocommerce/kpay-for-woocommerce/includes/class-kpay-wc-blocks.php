<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 区块结账（新版结账页）支持。不注册的话，使用区块结账的店铺看不到这个付款方式
 */
final class KPay_WC_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'kpay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_kpay_settings', []);
    }

    public function is_active()
    {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        return isset($gateways['kpay']) && $gateways['kpay']->is_available();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'kpay-wc-blocks',
            plugins_url('assets/blocks.js', KPAY_WC_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            KPAY_WC_VERSION,
            true
        );
        return ['kpay-wc-blocks'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => isset($this->settings['title']) ? $this->settings['title'] : 'KPay 凯付',
            'description' => isset($this->settings['description']) ? $this->settings['description'] : '',
            'supports' => ['products'],
        ];
    }
}
