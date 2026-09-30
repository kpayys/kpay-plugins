<?php

namespace gateway\kpay;

use app\common\lib\Plugin;
use gateway\kpay\lib\KpayClient;

require_once __DIR__ . '/lib/KpayClient.php';

/**
 * 智简魔方财务系统 V10 × KPay 支付接口
 *
 * 目录名必须是 kpay，放到 public/plugins/gateway/ 下。
 *
 * @title KPay 凯付
 * @desc 通过 KPay 收款，支持支付宝、微信支付、云闪付和原路退款
 * @author KPay
 * @version 1.0.0
 */
class Kpay extends Plugin
{
    public $info = [
        'name' => 'Kpay',
        'title' => 'KPay 凯付',
        'description' => '通过 KPay 收款，支持支付宝、微信支付、云闪付和原路退款',
        'author' => 'KPay',
        'version' => '1.0.0',
        'help_url' => 'https://github.com/kpayys/kpay-plugins/tree/main/zjmf',
        'author_url' => 'https://kaipay.cn',
        'url' => '',
    ];

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    /**
     * 发起支付：服务端向 KPay 下单，返回收银台里显示的付款按钮
     *
     * @param array $param 系统传入：out_trade_no / client / product / global / finance
     */
    public function KpayHandle($param)
    {
        $param = is_array($param) ? $param : [];
        $client = new KpayClient($this->getConfig());
        try {
            $params = $client->orderParams(
                (string)($param['out_trade_no'] ?? ''),
                $param['finance']['total'] ?? 0,
                self::subject($param),
                $this->callbackUrl('notifyHandle'),
                $this->callbackUrl('returnHandle'),
                KpayClient::device((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
            );
            $payUrl = $client->createOrder($params);
        } catch (\Exception $e) {
            return self::errorHtml($e->getMessage());
        }
        return self::payButtonHtml($payUrl);
    }

    /**
     * 原路退款
     *
     * @param array $param transaction_number / amount / out_request_no / total_fee
     */
    public function KpayHandleRefund($param)
    {
        $param = is_array($param) ? $param : [];
        $client = new KpayClient($this->getConfig());
        $requestNo = trim((string)($param['out_request_no'] ?? ''));
        if ($requestNo === '') {
            $requestNo = 'R' . date('YmdHis') . mt_rand(1000, 9999);
        }
        try {
            $client->refund(trim((string)($param['transaction_number'] ?? '')), $param['amount'] ?? 0, $requestNo);
        } catch (\Exception $e) {
            return ['status' => 400, 'msg' => $e->getMessage()];
        }
        return ['status' => 200, 'data' => ['trade_no' => $requestNo]];
    }

    public static function subject(array $param)
    {
        if (!empty($param['product']) && is_array($param['product'])) {
            $first = reset($param['product']);
            if (is_scalar($first) && trim((string)$first) !== '') {
                return (string)$first;
            }
        }
        return '订单 ' . ($param['out_trade_no'] ?? '');
    }

    public static function payButtonHtml($payUrl)
    {
        $safe = htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8');
        return '<div style="text-align:center;padding:24px 0;">'
            . '<a href="' . $safe . '" target="_blank" rel="noopener noreferrer" '
            . 'style="display:inline-block;padding:10px 32px;background:#1677ff;color:#fff;border-radius:4px;text-decoration:none;">前往付款</a>'
            . '<p style="margin-top:12px;color:#909399;font-size:13px;">付款完成后回到本页面刷新订单状态</p></div>';
    }

    public static function errorHtml($message)
    {
        return '<div style="padding:20px 0;text-align:center;color:#f56c6c;font-size:14px;">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    // 网站地址/gateway/kpay/index/<方法>
    private function callbackUrl($action)
    {
        return rtrim((string)configuration('website_url'), '/') . '/gateway/kpay/index/' . $action;
    }
}
