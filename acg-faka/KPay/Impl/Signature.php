<?php
declare(strict_types=1);

namespace App\Pay\KPay\Impl;

/**
 * KPay EPay V1 签名。
 *
 * 规则与 KPay 服务端一致：去掉 sign、sign_type 和空值，按参数名字节序升序，
 * 拼成 a=1&b=2 后直接接上密钥（中间没有 &），取 MD5 小写。
 */
class Signature implements \App\Pay\Signature
{
    /**
     * 异步通知验签。除了签名，还要求通知里的 pid 就是当前配置的商户ID，
     * 防止同一站点挂了多个 KPay 商户时，拿 A 商户的通知去结 B 商户的单。
     *
     * @param array $data
     * @param array $config
     * @return bool
     */
    public function verification(array $data, array $config): bool
    {
        $key = trim((string)($config['key'] ?? ''));
        $pid = trim((string)($config['pid'] ?? ''));
        $sign = $data['sign'] ?? null;

        if ($key === '' || $pid === '' || !is_string($sign)) {
            return false;
        }

        if ((string)($data['pid'] ?? '') !== $pid) {
            return false;
        }

        return hash_equals(self::generate($data, $key), strtolower(trim($sign)));
    }

    /**
     * @param array $params
     * @param string $key
     * @return string
     */
    public static function generate(array $params, string $key): string
    {
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $name => $value) {
            $name = (string)$name;
            if ($name === 'sign' || $name === 'sign_type') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string)$value;
            if (trim($value) === '') {
                continue;
            }
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs) . $key);
    }
}
