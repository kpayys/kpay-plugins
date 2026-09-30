<?php

namespace gateway\kpay\controller;

use app\home\controller\BaseController;
use gateway\kpay\Kpay;
use gateway\kpay\lib\KpayClient;

require_once dirname(__DIR__) . '/lib/KpayClient.php';

/**
 * KPay 回调
 *
 *   异步通知  网站地址/gateway/kpay/index/notifyHandle
 *   同步跳转  网站地址/gateway/kpay/index/returnHandle
 *
 * KPay 的异步通知和同步跳转都是 GET 方式，参数相同。
 */
class IndexController extends BaseController
{
    public function notifyHandle()
    {
        echo self::handle($_GET, (new Kpay())->getConfig()) ? 'success' : 'fail';
    }

    /**
     * 同步跳转也尝试入账一次（入账接口对已支付订单是幂等的），再跳回订单页
     */
    public function returnHandle()
    {
        self::handle($_GET, (new Kpay())->getConfig());
        $tmpOrderId = (string)($_GET['out_trade_no'] ?? '');
        if ($tmpOrderId === '') {
            return redirect((string)configuration('website_url'));
        }
        return get_gateway_return_url($tmpOrderId);
    }

    /**
     * 验签 → 状态 → 入账。入账交给智简魔方内置的 order_pay_handle，
     * 金额取通知里的 money（等于下单金额，KPay 签过名，无法篡改）
     */
    public static function handle(array $data, array $config)
    {
        if (empty($data)) {
            return false;
        }
        $client = new KpayClient($config);
        if (!$client->verifyNotify($data)) {
            return false;
        }
        if ((string)($data['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return false;
        }
        try {
            order_pay_handle([
                'tmp_order_id' => (string)($data['out_trade_no'] ?? ''),
                'amount' => (string)($data['money'] ?? ''),
                // 原路退款时作为 transaction_number 传回
                'trans_id' => (string)($data['trade_no'] ?? ''),
                'currency' => 'CNY',
                'paid_time' => date('Y-m-d H:i:s'),
                'gateway' => 'Kpay',
            ]);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }
}
