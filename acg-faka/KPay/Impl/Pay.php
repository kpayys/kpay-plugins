<?php
declare(strict_types=1);

namespace App\Pay\KPay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use GuzzleHttp\Client;
use Kernel\Exception\JSONException;

class Pay extends Base implements \App\Pay\Pay
{
    private const DEFAULT_GATEWAY = 'https://api.kaipay.cn';

    /**
     * @return PayEntity
     * @throws JSONException
     */
    public function trade(): PayEntity
    {
        $pid = trim((string)($this->config['pid'] ?? ''));
        $key = trim((string)($this->config['key'] ?? ''));
        if ($pid === '' || $key === '') {
            throw new JSONException('KPay 商户ID或商户密钥未配置');
        }

        $gateway = self::gateway((string)($this->config['url'] ?? ''));

        // 只能传 KPay 认识的字段：clientip、param 这类扩展字段 KPay 不参与验签，
        // 带上会导致签名对不上。
        $params = [
            'pid' => $pid,
            'out_trade_no' => $this->tradeNo,
            'notify_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'name' => $this->subject(),
            'money' => number_format($this->amount, 2, '.', ''),
            'device' => self::device(),
        ];

        $type = self::payType($this->code);
        if ($type !== '') {
            $params['type'] = $type;
        }

        $params['sign'] = Signature::generate($params, $key);
        $params['sign_type'] = 'MD5';

        if (($this->config['mode'] ?? 'mapi') === 'submit') {
            $payEntity = new PayEntity();
            $payEntity->setType(self::TYPE_SUBMIT);
            $payEntity->setUrl($gateway . '/epay/submit');
            $payEntity->setOption($params);
            return $payEntity;
        }

        $payEntity = new PayEntity();
        $payEntity->setType(self::TYPE_REDIRECT);
        $payEntity->setUrl($this->createPayUrl($gateway, $params));
        return $payEntity;
    }

    /**
     * 服务端调 mapi 下单，拿到付款页地址。
     *
     * @param string $gateway
     * @param array $params
     * @return string
     * @throws JSONException
     */
    private function createPayUrl(string $gateway, array $params): string
    {
        try {
            $response = (new Client(['timeout' => 15, 'connect_timeout' => 5]))->post($gateway . '/epay/mapi', [
                'form_params' => $params,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $body = (string)$response->getBody();
        } catch (\Throwable $e) {
            $this->log("下单请求失败 [{$this->tradeNo}]: " . $e->getMessage());
            throw new JSONException('支付网关连接失败，请稍后重试');
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            $this->log("下单响应无法解析 [{$this->tradeNo}]: " . mb_substr($body, 0, 300));
            throw new JSONException('支付网关返回异常，请稍后重试');
        }

        if ((int)($result['code'] ?? 0) !== 1) {
            $message = trim((string)($result['msg'] ?? ''));
            $this->log("下单失败 [{$this->tradeNo}]: " . ($message !== '' ? $message : $body));
            throw new JSONException($message !== '' ? $message : '下单失败，请稍后重试');
        }

        foreach (['payurl', 'qrcode'] as $field) {
            $url = trim((string)($result[$field] ?? ''));
            if (preg_match('#^https?://#i', $url)) {
                return $url;
            }
        }

        $this->log("下单成功但没有返回付款地址 [{$this->tradeNo}]: " . $body);
        throw new JSONException('支付网关未返回付款地址，请稍后重试');
    }

    /**
     * @return string
     */
    private function subject(): string
    {
        $subject = trim((string)($this->config['subject'] ?? ''));
        if ($subject === '') {
            return '订单 ' . $this->tradeNo;
        }
        return mb_substr(str_replace('{tradeNo}', $this->tradeNo, $subject), 0, 64);
    }

    /**
     * 允许后台填 https://api.kaipay.cn、https://api.kaipay.cn/ 或 https://api.kaipay.cn/epay/。
     *
     * @param string $url
     * @return string
     * @throws JSONException
     */
    public static function gateway(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return self::DEFAULT_GATEWAY;
        }
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            throw new JSONException('KPay 接口地址格式不正确');
        }
        return (string)preg_replace('#/epay$#i', '', $url);
    }

    /**
     * 站点后台的通道编码 → KPay 的 type。cashier 表示不指定，由付款人在收银台里选。
     *
     * @param string $code
     * @return string
     */
    public static function payType(string $code): string
    {
        $code = strtolower(trim($code));
        switch ($code) {
            case 'alipay':
            case 'zfb':
                return 'alipay';
            case 'wxpay':
            case 'wechat':
            case 'wx':
                return 'wxpay';
            case 'unionpay':
            case 'bank':
            case 'ysf':
            case 'cloudpay':
                return 'unionpay';
            case '':
            case 'cashier':
                return '';
            default:
                return $code;
        }
    }

    /**
     * 下单发生在付款人的请求里，按 UA 判断终端，KPay 据此选手机/电脑付款产品。
     *
     * @return string
     */
    public static function device(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua !== '' && preg_match('/Mobile|Android|iPhone|iPad|iPod|MicroMessenger|AlipayClient|HarmonyOS/i', $ua)) {
            return 'mobile';
        }
        return 'pc';
    }
}
