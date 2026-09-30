# KPay 对接协议速查

本仓库的插件都走 KPay 的易支付（EPay V1）兼容接口。给其他系统写插件时按这里来即可，完整文档见 KPay 商户后台的「EPay 文档」。

## 地址

| 用途 | 方法 | 地址 |
|---|---|---|
| 页面跳转下单 | GET / POST | `https://api.kaipay.cn/epay/submit` |
| 服务端下单 | GET / POST | `https://api.kaipay.cn/epay/mapi` |
| 查询订单 | GET | `https://api.kaipay.cn/epay/api?act=order` |

也兼容 `submit.php`、`mapi.php`、`api.php` 的旧路径。

## 下单参数

| 参数 | 必填 | 说明 |
|---|---|---|
| `pid` | 是 | 商户ID |
| `type` | 否 | `alipay` / `wxpay` / `unionpay`；不传则打开收银台让付款人选择 |
| `out_trade_no` | 是 | 你的订单号，同一商户下唯一 |
| `notify_url` | 是 | 异步通知地址 |
| `return_url` | 否 | 支付完成后浏览器跳回的地址 |
| `name` | 是 | 商品名称 |
| `money` | 是 | 金额，两位小数 |
| `device` | 否 | `pc` / `mobile`；服务端下单时建议传，决定用手机还是电脑的付款产品 |
| `sign` | 是 | 签名 |
| `sign_type` | 否 | 固定 `MD5` |

**只传上表里的参数。** `clientip`、`param` 等其他易支付实现里常见的扩展参数不参与 KPay 的验签，带上会签名失败。

`mapi` 返回 JSON：`code` 为 `1` 表示成功，`payurl` 是付款页地址，直接跳转即可；失败时看 `msg`。

## 签名

1. 去掉 `sign`、`sign_type` 和值为空的参数；
2. 按参数名 ASCII 升序排列，拼成 `a=1&b=2`（值不做 URL 编码）；
3. 末尾直接接上密钥（中间没有 `&`）；
4. MD5，取 32 位小写。

```php
function kpay_sign(array $params, string $key): string
{
    ksort($params, SORT_STRING);
    $pairs = [];
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type' || trim((string)$v) === '') {
            continue;
        }
        $pairs[] = $k . '=' . $v;
    }
    return md5(implode('&', $pairs) . $key);
}
```

## 异步通知

支付成功后 KPay 以 **GET** 请求 `notify_url`，参数：

`pid`、`trade_no`（KPay 订单号）、`out_trade_no`、`type`、`name`、`money`、`trade_status`（固定 `TRADE_SUCCESS`）、`sign`、`sign_type`。

使用了优惠券或附加服务费的订单还会多带 `gross_amount`、`payable_amount`、`coupon_discount_amount`、`surcharge_amount`、`principal_amount`，它们都参与签名，验签时对收到的全部参数统一计算即可。

处理要求：

- 验签，并确认 `pid` 是你自己的商户ID；
- `money` 是下单时的金额，与本地订单金额一致再入账；
- 处理成功后返回纯文本 `success`，否则 KPay 会重试；
- 同一笔订单可能收到多次通知，入账要幂等。

同步跳转 `return_url` 带的参数和签名与异步通知相同，只用于展示结果，入账以异步通知为准。

## 退款（可选）

EPay 协议本身没有退款。需要程序化退款时，用 KPay「API 密钥」创建一把带 **订单退款** 权限的 Key，调用：

```
POST https://api.kaipay.cn/pay/api/order/refund
Content-Type: application/json

{"orderNo": "KPay 订单号 trade_no", "refundAmount": 1.00, "refundRequestNo": "你的退款单号", "reason": "原因"}
```

请求头按 HMAC-SHA256 签名：

```
X-API-Key: <API Key>
X-KPay-Timestamp: <Unix 秒>
X-KPay-Nonce: <8-128 位随机串，不可重复>
X-KPay-Body-SHA256: <sha256(请求体) hex>
X-KPay-Signature-Method: HMAC-SHA256
X-KPay-Signature: hex(hmac_sha256(API Secret, "POST\n/pay/api/order/refund\n<时间戳>\n<nonce>\n<body sha256>"))
```

返回 JSON 的 `code` 为 `0` 表示退款已受理。同一笔退款重试时 `refundRequestNo` 保持不变。实现参考 [`KpayClient::api()`](../epay/plugins/kpay/inc/KpayClient.php)。GET 请求签名时 PATH 要带上实际发送的查询串，空请求体用空字符串的 SHA256。

## 服务商进件

服务商用同一套 HMAC 签名调用 `/pay/api/onboarding/*`（进件）和 `/pay/api/service-provider/*`（旗下商户、分润、费率调整、投诉），完整字段见 KPay 后台的服务商 API 文档，易支付服务商模块是一份可运行的参考实现：[`KpayService`](../epay/plugins/kpay/inc/KpayService.php)。

材料上传是 `multipart/form-data`，签名对整个请求体的原始字节计算，所以要先把表单字节拼好再签名、发送同一份内容（见 `KpayClient::upload()`）。

建单时传了 `callbackUrl` 和 `callbackSecret`，进件状态变化会 POST 到回调地址：

```
X-KPay-Event: onboarding.application.activated
X-KPay-Timestamp: <Unix 秒>
X-KPay-Nonce: <随机串>
X-KPay-Body-SHA256: <sha256(请求体) hex>
X-KPay-Signature: hex(hmac_sha256(callbackSecret, "<时间戳>\n<nonce>\n<event>\n<body sha256>"))
```

验签后按 `eventId` 幂等处理并返回 2xx，非 2xx 会重试。回调只作通知，关键状态以查询接口为准。
