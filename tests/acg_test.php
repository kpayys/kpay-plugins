<?php
/**
 * 异次元发卡插件测试（由 run.php 引入）
 */

// ─── 异次元发卡运行时的最小替身 ─────────────────────────────────────

namespace Kernel\Exception {
    class JSONException extends \Exception
    {
    }
}

namespace App\Entity {
    class PayEntity
    {
        private int $type;
        private string $url;
        private array $option = [];

        public function getType(): int { return $this->type; }
        public function setType(int $type): void { $this->type = $type; }
        public function getUrl(): string { return $this->url; }
        public function setUrl(string $url): void { $this->url = $url; }
        public function getOption(): array { return $this->option; }
        public function setOption(array $option): void { $this->option = $option; }
    }
}

namespace App\Consts {
    interface Pay
    {
        const IS_SIGN = 0x1;
        const IS_STATUS = 0x4;
        const FIELD_STATUS_KEY = 0x2;
        const FIELD_STATUS_VALUE = 0x3;
        const FIELD_ORDER_KEY = 0x5;
        const FIELD_AMOUNT_KEY = 0x6;
        const FIELD_RESPONSE = 0x7;
    }
}

namespace App\Pay {
    interface Signature
    {
        public function verification(array $data, array $config): bool;
    }

    interface Pay
    {
        const TYPE_REDIRECT = 2;
        const TYPE_LOCAL_RENDER = 3;
        const TYPE_SUBMIT = 4;

        public function trade(): \App\Entity\PayEntity;
    }

    abstract class Base
    {
        public float $amount;
        public string $tradeNo;
        public array $config;
        public string $callbackUrl;
        public string $returnUrl;
        public string $clientIp;
        public string $code;
        public string $handle;

        protected function log(string $message): void
        {
        }
    }
}

// ─── 异次元发卡插件 ─────────────────────────────────────────────────

namespace {
    $acgRoot = dirname(__DIR__) . '/acg-faka/KPay';
    require $acgRoot . '/Impl/Signature.php';
    require $acgRoot . '/Impl/Pay.php';

    $info = require $acgRoot . '/Config/Info.php';
    check(isset($info['callback'][\App\Consts\Pay::FIELD_AMOUNT_KEY]), 'acg: Info.php 有回调定义');
    check(is_array(require $acgRoot . '/Config/Submit.php'), 'acg: Submit.php 返回数组');
    check(is_array(require $acgRoot . '/Config/Config.php'), 'acg: Config.php 返回数组');

    foreach (VECTOR_CASES as $i => $case) {
        check(\App\Pay\KPay\Impl\Signature::generate($case['params'], VECTOR_KEY) === $case['sign'], "acg: 签名向量 #{$i}");
    }

    $notify = VECTOR_CASES[2]['params'];
    $notify['sign'] = VECTOR_CASES[2]['sign'];
    $notify['sign_type'] = 'MD5';
    $config = ['pid' => '1001', 'key' => VECTOR_KEY];
    $verifier = new \App\Pay\KPay\Impl\Signature();

    check($verifier->verification($notify, $config), 'acg: 合法通知验签通过');
    check($verifier->verification(array_merge($notify, ['sign' => strtoupper($notify['sign'])]), $config), 'acg: 签名大小写不敏感');
    check(!$verifier->verification(array_merge($notify, ['money' => '999.00']), $config), 'acg: 篡改金额被拒');
    check(!$verifier->verification($notify, ['pid' => '1002', 'key' => VECTOR_KEY]), 'acg: 其他商户的通知被拒');
    check(!$verifier->verification($notify, ['pid' => '1001', 'key' => '']), 'acg: 未配置密钥时拒绝');
    check(!$verifier->verification(array_diff_key($notify, ['sign' => 1]), $config), 'acg: 缺少签名被拒');

    check(\App\Pay\KPay\Impl\Pay::payType('bank') === 'unionpay', 'acg: bank → unionpay');
    check(\App\Pay\KPay\Impl\Pay::payType('cashier') === '', 'acg: cashier 不指定支付方式');
    check(\App\Pay\KPay\Impl\Pay::gateway('') === 'https://api.kaipay.cn', 'acg: 默认网关');
    check(\App\Pay\KPay\Impl\Pay::gateway('https://api.kaipay.cn/epay/') === 'https://api.kaipay.cn', 'acg: 去掉 /epay/ 后缀');

    $threw = false;
    try {
        \App\Pay\KPay\Impl\Pay::gateway('api.kaipay.cn');
    } catch (\Kernel\Exception\JSONException $e) {
        $threw = true;
    }
    check($threw, 'acg: 非法网关地址报错');

    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) MicroMessenger/8.0';
    check(\App\Pay\KPay\Impl\Pay::device() === 'mobile', 'acg: 手机 UA 识别为 mobile');
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
    check(\App\Pay\KPay\Impl\Pay::device() === 'pc', 'acg: 电脑 UA 识别为 pc');

    $pay = new \App\Pay\KPay\Impl\Pay();
    $pay->amount = 12.5;
    $pay->tradeNo = '202609301234567';
    $pay->config = ['url' => 'https://api.kaipay.cn/', 'pid' => '1001', 'key' => VECTOR_KEY, 'mode' => 'submit', 'subject' => '卡密 {tradeNo}'];
    $pay->callbackUrl = 'https://shop.example.com/user/api/order/callback.202609301234567';
    $pay->returnUrl = 'https://shop.example.com/user/index/query?tradeNo=202609301234567';
    $pay->clientIp = '127.0.0.1';
    $pay->code = 'wxpay';
    $pay->handle = 'KPay';
    $entity = $pay->trade();
    $option = $entity->getOption();

    check($entity->getType() === \App\Pay\Pay::TYPE_SUBMIT, 'acg: 表单模式返回 TYPE_SUBMIT');
    check($entity->getUrl() === 'https://api.kaipay.cn/epay/submit', 'acg: 表单提交到 /epay/submit');
    check($option['money'] === '12.50', 'acg: 金额两位小数');
    check($option['type'] === 'wxpay', 'acg: 支付方式');
    check($option['name'] === '卡密 202609301234567', 'acg: 订单标题替换订单号');
    check(!isset($option['clientip']) && !isset($option['param']), 'acg: 不带 KPay 不验签的字段');
    check(\App\Pay\KPay\Impl\Signature::generate($option, VECTOR_KEY) === $option['sign'], 'acg: 下单参数签名正确');

    $pay->config['key'] = '';
    $threw = false;
    try {
        $pay->trade();
    } catch (\Kernel\Exception\JSONException $e) {
        $threw = true;
    }
    check($threw, 'acg: 未配置密钥时下单报错');
}
