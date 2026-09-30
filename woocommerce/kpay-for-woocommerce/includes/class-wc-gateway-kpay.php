<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_KPay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'kpay';
        $this->method_title = 'KPay 凯付';
        $this->method_description = '用 KPay 收款，支持支付宝、微信支付、云闪付。店铺货币需要是人民币（CNY）。';
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_kpay', [$this, 'handle_notify']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => '启用',
                'type' => 'checkbox',
                'label' => '启用 KPay 凯付',
                'default' => 'no',
            ],
            'title' => [
                'title' => '显示名称',
                'type' => 'text',
                'description' => '结账页上付款方式的名称',
                'default' => '支付宝 / 微信支付',
            ],
            'description' => [
                'title' => '说明',
                'type' => 'textarea',
                'default' => '下单后跳转到付款页完成支付。',
            ],
            'api_url' => [
                'title' => '接口地址',
                'type' => 'text',
                'default' => 'https://api.kaipay.cn/',
                'description' => '一般不用改',
            ],
            'pid' => [
                'title' => '商户ID',
                'type' => 'text',
                'description' => 'KPay 商户后台「EPay 接入 → EPay 配置」里的商户ID',
            ],
            'key' => [
                'title' => '商户密钥',
                'type' => 'password',
                'description' => 'KPay「API 密钥」页创建 EPay 兼容密钥时显示的 EPay Key',
            ],
            'pay_type' => [
                'title' => '支付方式',
                'type' => 'select',
                'default' => 'cashier',
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
                'description' => '选填。KPay「API 密钥」里创建的平台 API 密钥（勾选「发起退款」权限），填了才能在订单页退款',
            ],
            'refund_api_secret' => [
                'title' => '退款 API Secret',
                'type' => 'password',
                'description' => '选填。与上面的 API Key 配对',
            ],
        ];
    }

    public function is_available()
    {
        if (get_woocommerce_currency() !== 'CNY') {
            return false;
        }
        return parent::is_available() && $this->get_option('pid') !== '' && $this->get_option('key') !== '';
    }

    public function admin_options()
    {
        if (get_woocommerce_currency() !== 'CNY') {
            echo '<div class="notice notice-warning inline"><p>KPay 只收人民币，店铺货币不是 CNY 时结账页不会显示这个付款方式。</p></div>';
        }
        echo '<p>异步通知地址（自动使用，无需填写）：<code>' . esc_html($this->notify_url()) . '</code></p>';
        parent::admin_options();
    }

    public function notify_url()
    {
        return WC()->api_request_url('kpay');
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $pid = trim($this->get_option('pid'));
        $key = trim($this->get_option('key'));

        $params = [
            'pid' => $pid,
            'out_trade_no' => KPay_WC_Client::outTradeNo($order->get_id()),
            'notify_url' => $this->notify_url(),
            'return_url' => $this->get_return_url($order),
            'name' => mb_substr(sprintf('订单 %s', $order->get_order_number()), 0, 64),
            'money' => number_format((float)$order->get_total(), 2, '.', ''),
            'device' => KPay_WC_Client::device(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : ''),
        ];
        $type = KPay_WC_Client::payType($this->get_option('pay_type'));
        if ($type !== '') {
            $params['type'] = $type;
        }
        $params['sign'] = KPay_WC_Client::sign($params, $key);
        $params['sign_type'] = 'MD5';

        $response = wp_remote_post(KPay_WC_Client::normalizeGateway($this->get_option('api_url')) . 'epay/mapi', [
            'timeout' => 15,
            'body' => $params,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            wc_add_notice('支付网关连接失败，请稍后重试', 'error');
            $order->add_order_note('KPay 下单请求失败：' . $response->get_error_message());
            return ['result' => 'failure'];
        }
        $result = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($result) || (int)($result['code'] ?? 0) !== 1) {
            $message = is_array($result) && !empty($result['msg']) ? $result['msg'] : '下单失败，请稍后重试';
            wc_add_notice($message, 'error');
            $order->add_order_note('KPay 下单失败：' . $message);
            return ['result' => 'failure'];
        }
        foreach (['payurl', 'qrcode'] as $field) {
            $url = trim((string)($result[$field] ?? ''));
            if (preg_match('#^https?://#i', $url)) {
                $order->update_status('pending', '等待付款人在 KPay 完成支付');
                return ['result' => 'success', 'redirect' => $url];
            }
        }
        wc_add_notice('支付网关未返回付款地址', 'error');
        return ['result' => 'failure'];
    }

    /**
     * KPay 以 GET 方式通知到 /wc-api/kpay/（或 ?wc-api=kpay）
     */
    public function handle_notify()
    {
        // WordPress 会给 $_GET 加反斜杠，先还原；wc-api 是路由参数，不参与签名
        $data = wp_unslash($_GET);
        unset($data['wc-api']);
        echo $this->process_notify(is_array($data) ? $data : []);
        exit;
    }

    public function process_notify(array $data)
    {
        if (!KPay_WC_Client::verify($data, $this->get_option('pid'), $this->get_option('key'))) {
            return 'fail';
        }
        if (($data['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return 'fail';
        }
        $order = wc_get_order(KPay_WC_Client::orderIdFromOutTradeNo($data['out_trade_no'] ?? ''));
        if (!$order || $order->get_payment_method() !== $this->id) {
            return 'fail';
        }
        if ($order->is_paid()) {
            return 'success';
        }
        if (round((float)($data['money'] ?? 0), 2) != round((float)$order->get_total(), 2)) {
            $order->add_order_note('KPay 通知金额与订单不符：' . ($data['money'] ?? ''));
            return 'fail';
        }
        $order->payment_complete((string)$data['trade_no']);
        $order->add_order_note('KPay 支付成功，平台订单号 ' . $data['trade_no']);
        return 'success';
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        $apiKey = trim($this->get_option('refund_api_key'));
        $apiSecret = trim($this->get_option('refund_api_secret'));
        if ($apiKey === '' || $apiSecret === '') {
            return new WP_Error('kpay_refund', '未配置退款 API Key，请到 KPay 商户后台操作退款');
        }
        if (!$order || $order->get_transaction_id() === '') {
            return new WP_Error('kpay_refund', '缺少 KPay 订单号');
        }

        $path = '/pay/api/order/refund';
        $body = wp_json_encode([
            'orderNo' => $order->get_transaction_id(),
            'refundAmount' => round((float)$amount, 2),
            'refundRequestNo' => 'R' . $order->get_id() . 'T' . time(),
            'reason' => $reason !== '' ? mb_substr($reason, 0, 100) : 'WooCommerce 退款',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            KPay_WC_Client::hmacHeaders($apiKey, $apiSecret, 'POST', $path, $body));

        $response = wp_remote_post(rtrim(KPay_WC_Client::normalizeGateway($this->get_option('api_url')), '/') . $path, [
            'timeout' => 15,
            'headers' => $headers,
            'body' => $body,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('kpay_refund', '连接 KPay 失败：' . $response->get_error_message());
        }
        $result = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($result) || (int)($result['code'] ?? -1) !== 0) {
            return new WP_Error('kpay_refund', is_array($result) && !empty($result['msg']) ? $result['msg'] : '退款失败');
        }
        $order->add_order_note('KPay 退款已受理：' . wc_price($amount));
        return true;
    }
}
