<?php
/**
 * WooCommerce 插件测试（由 run.php 引入）：用替身模拟 WordPress / WooCommerce 函数
 */

define('ABSPATH', __DIR__);

$GLOBALS['__wc'] = ['currency' => 'CNY', 'orders' => [], 'notices' => [], 'remote' => [], 'remote_responses' => []];

function add_action() {}
function add_filter() {}
function get_woocommerce_currency() { return $GLOBALS['__wc']['currency']; }
function wc_get_order($id) { return isset($GLOBALS['__wc']['orders'][$id]) ? $GLOBALS['__wc']['orders'][$id] : false; }
function wc_add_notice($message, $type = 'success') { $GLOBALS['__wc']['notices'][] = $message; }
function wc_price($amount) { return '¥' . $amount; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_post($url, $args) {
    $GLOBALS['__wc']['remote'][] = ['url' => $url, 'args' => $args];
    return ['body' => json_encode(array_shift($GLOBALS['__wc']['remote_responses']))];
}
function WC() {
    return new class {
        public function api_request_url($name) { return 'https://shop.example.com/wc-api/' . $name . '/'; }
    };
}

class WP_Error
{
    public $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class WC_Payment_Gateway
{
    public $id;
    public $settings = [];
    public $form_fields = [];
    public $method_title = '';
    public $method_description = '';
    public $has_fields = false;
    public $supports = ['products'];
    public $title = '';
    public $description = '';
    public $enabled = 'no';
    public function init_settings()
    {
        foreach ($this->form_fields as $key => $field) {
            if (!array_key_exists($key, $this->settings)) {
                $this->settings[$key] = isset($field['default']) ? $field['default'] : '';
            }
        }
    }
    public function get_option($key) { return isset($this->settings[$key]) ? $this->settings[$key] : ''; }
    public function is_available() { return $this->get_option('enabled') === 'yes'; }
    public function get_return_url($order) { return 'https://shop.example.com/checkout/order-received/' . $order->get_id() . '/?key=wc_order_x'; }
}

class FakeWcOrder
{
    public $id;
    public $total;
    public $paid = false;
    public $transactionId = '';
    public $method = 'kpay';
    public $notes = [];
    public function __construct($id, $total) { $this->id = $id; $this->total = $total; }
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string)$this->id; }
    public function get_total() { return $this->total; }
    public function get_payment_method() { return $this->method; }
    public function is_paid() { return $this->paid; }
    public function payment_complete($transactionId) { $this->paid = true; $this->transactionId = $transactionId; }
    public function get_transaction_id() { return $this->transactionId; }
    public function add_order_note($note) { $this->notes[] = $note; }
    public function update_status($status, $note = '') {}
}

$wcRoot = dirname(__DIR__) . '/woocommerce/kpay-for-woocommerce';
require $wcRoot . '/includes/class-kpay-wc-client.php';
require $wcRoot . '/includes/class-wc-gateway-kpay.php';

foreach (VECTOR_CASES as $i => $case) {
    check(KPay_WC_Client::sign($case['params'], VECTOR_KEY) === $case['sign'], "woo: 签名向量 #{$i}");
}
check(KPay_WC_Client::orderIdFromOutTradeNo('88T1790800000') === 88, 'woo: 从订单号取回订单ID');

$gateway = new WC_Gateway_KPay();
$gateway->settings = array_merge($gateway->settings, ['enabled' => 'yes', 'pid' => '1001', 'key' => VECTOR_KEY, 'pay_type' => 'alipay']);
check($gateway->supports === ['products', 'refunds'], 'woo: 支持退款');
check($gateway->is_available(), 'woo: 人民币店铺可用');
$GLOBALS['__wc']['currency'] = 'USD';
check(!$gateway->is_available(), 'woo: 非人民币店铺不显示');
$GLOBALS['__wc']['currency'] = 'CNY';

$order = new FakeWcOrder(88, '199.00');
$GLOBALS['__wc']['orders'][88] = $order;
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) Mobile';
$GLOBALS['__wc']['remote_responses'][] = ['code' => 1, 'payurl' => 'https://api.kaipay.cn/checkout/abc'];
$result = $gateway->process_payment(88);
$sent = $GLOBALS['__wc']['remote'][0]['args']['body'];
check($result === ['result' => 'success', 'redirect' => 'https://api.kaipay.cn/checkout/abc'], 'woo: 下单后跳转付款页');
check($GLOBALS['__wc']['remote'][0]['url'] === 'https://api.kaipay.cn/epay/mapi', 'woo: 调 mapi 下单');
check($sent['type'] === 'alipay' && $sent['device'] === 'mobile' && $sent['money'] === '199.00' && $sent['notify_url'] === 'https://shop.example.com/wc-api/kpay/', 'woo: 下单参数');
check(KPay_WC_Client::sign($sent, VECTOR_KEY) === $sent['sign'], 'woo: 下单签名正确');
$GLOBALS['__wc']['remote_responses'][] = ['code' => -1, 'msg' => '商户未开通'];
check($gateway->process_payment(88) === ['result' => 'failure'] && end($GLOBALS['__wc']['notices']) === '商户未开通', 'woo: 下单失败提示原因');

$notify = ['pid' => '1001', 'trade_no' => 'P9', 'out_trade_no' => $sent['out_trade_no'], 'type' => 'alipay', 'name' => "订单 88 & 'x'", 'money' => '199.00', 'trade_status' => 'TRADE_SUCCESS'];
$notify['sign'] = KPay_WC_Client::sign($notify, VECTOR_KEY);
$notify['sign_type'] = 'MD5';
check($gateway->process_notify(array_merge($notify, ['money' => '1.00'])) === 'fail' && !$order->paid, 'woo: 篡改金额被拒');
$order->total = '200.00';
check($gateway->process_notify($notify) === 'fail' && !$order->paid, 'woo: 金额与订单不符不入账');
$order->total = '199.00';
$order->method = 'cod';
check($gateway->process_notify($notify) === 'fail', 'woo: 不是 KPay 的订单不处理');
$order->method = 'kpay';

// 模拟 WordPress 给 $_GET 加反斜杠、路由参数 wc-api 混在查询串里
$_GET = array_map('addslashes', array_merge($notify, ['wc-api' => 'kpay']));
ob_start();
$data = wp_unslash($_GET);
unset($data['wc-api']);
echo $gateway->process_notify($data);
check(ob_get_clean() === 'success' && $order->paid && $order->transactionId === 'P9', 'woo: 还原反斜杠并去掉路由参数后验签入账');
check($gateway->process_notify($notify) === 'success', 'woo: 重复通知直接返回 success');

$refund = $gateway->process_refund(88, 50, '');
check($refund instanceof WP_Error, 'woo: 未配置退款 Key 时拒绝');
$gateway->settings['refund_api_key'] = 'ak';
$gateway->settings['refund_api_secret'] = 'sk';
$GLOBALS['__wc']['remote'] = [];
$GLOBALS['__wc']['remote_responses'][] = ['code' => 0, 'msg' => 'ok'];
check($gateway->process_refund(88, 50, '客户取消') === true, 'woo: 退款成功');
$req = $GLOBALS['__wc']['remote'][0];
$h = array_change_key_case($req['args']['headers'], CASE_LOWER);
$canonical = implode("\n", ['POST', '/pay/api/order/refund', $h['x-kpay-timestamp'], $h['x-kpay-nonce'], hash('sha256', $req['args']['body'])]);
check(hash_equals(hash_hmac('sha256', $canonical, 'sk'), $h['x-kpay-signature']) && json_decode($req['args']['body'], true)['orderNo'] === 'P9', 'woo: 退款请求签名和单号');
