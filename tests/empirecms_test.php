<?php
/**
 * 帝国CMS 插件测试（由 run.php 引入）
 */

require dirname(__DIR__) . '/empirecms/e/payapi/kpay/kpay_lib.php';

foreach (VECTOR_CASES as $i => $case) {
    check(kpay_sign($case['params'], VECTOR_KEY) === $case['sign'], "empirecms: 签名向量 #{$i}");
}
$ecNotify = VECTOR_CASES[2]['params'];
$ecNotify['sign'] = VECTOR_CASES[2]['sign'];
check(kpay_verify($ecNotify, '1001', VECTOR_KEY) && !kpay_verify(array_merge($ecNotify, ['money' => '1']), '1001', VECTOR_KEY), 'empirecms: 通知验签');
check(!kpay_verify($ecNotify, '1002', VECTOR_KEY), 'empirecms: 其他商户被拒');
check(kpay_gateway('') === 'https://api.kaipay.cn/' && kpay_gateway('https://api.kaipay.cn/epay') === 'https://api.kaipay.cn/', 'empirecms: 接口地址');
check(kpay_to_utf8(mb_convert_encoding('购买点数,UID:1', 'GBK', 'UTF-8')) === '购买点数,UID:1', 'empirecms: GB2312 商品名转回 UTF-8');
check(kpay_to_utf8('已经是 UTF-8') === '已经是 UTF-8', 'empirecms: UTF-8 商品名保持不变');
check(kpay_out_trade_no('DD123') === 'DD123' && preg_match('/^EC\d{20}$/', kpay_out_trade_no('')) === 1, 'empirecms: 订单号');
$sql = file_get_contents(dirname(__DIR__) . '/empirecms/install.sql');
check(strpos($sql, "'kpay'") !== false && strpos($sql, 'phome_enewspayapi') !== false, 'empirecms: 安装 SQL');
