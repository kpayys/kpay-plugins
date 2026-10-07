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

其他易支付实现里常见的 `clientip`、`sitename`、`cid`、`param` 等扩展参数可以照常带上，只要它们参与了签名，KPay 会一并验签；但 KPay 不使用这些参数，异步通知里也不会回传 `param`。

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

EPay 协议本身没有退款。需要程序化退款时，用 KPay「API 密钥」创建一把「平台 API」密钥并勾选 **发起退款** 权限，调用：

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

## 服务商代收款

商户在 KPay 同意服务商的「代为收款」授权后，服务商用自己的平台 API 密钥调 V3 支付接口，请求头带上 `X-KPay-Sub-Merchant: <渠道商户ID>`，就以这个商户的身份下单、查单、关单、退款，款项照常由机构直接结算给商户。商户不用建 EPay 密钥，也不用验证你的域名。

1. `POST /pay/api/service-provider/merchants/<渠道商户ID>/authorization`，请求体 `{"scopes":["payment"],"note":"给商户看的说明"}`，商户在 KPay「账户中心 · 服务商授权」同意后生效。授权状态在 `GET /pay/api/service-provider/merchants/<渠道商户ID>` 的 `authorization` 里。
2. 下单 `POST /pay/api/v3/order/create`：

```json
{"merchantOrderNo":"你的订单号","amount":58,"productName":"会员充值","paymentMode":"checkout",
 "notifyUrl":"https://你的域名/notify","returnUrl":"https://你的域名/return"}
```

   返回的 `checkoutUrl` 是相对路径 `/checkout/xxx`，拼到收银台域名上（`api.kaipay.cn` 对应 `pay.kaipay.cn`），让付款人打开。
3. 支付结果 POST 到 `notifyUrl`，JSON 请求体（`orderNo`、`merchantOrderNo`、`amount`、`status`，支付成功时 `status` 为 `paid`），签名头同上面的进件回调，密钥是这把 API 密钥的 Secret，事件名形如 `payment.order.paid`。
4. 查单 `GET /pay/api/v3/order/query?orderNo=`、退款 `POST /pay/api/v3/order/refund`（`orderNo`、`refundAmount`、`refundRequestNo`、`reason`），都要用下单时的同一把密钥并带同样的 `X-KPay-Sub-Merchant`。

密钥要同时勾选「代商户收款」和创建订单 / 查询订单 / 发起退款。实现参考易支付插件的 [`_createDelegatedOrder`](../epay/plugins/kpay/kpay_plugin.php)。

## 服务商事件推送

`PUT /pay/api/service-provider/webhook`（`{"url":"...","events":[]}`）设置一个推送地址，首次设置时返回只显示一次的签名密钥。订单支付、退款、机构结算、分润、投诉、进件状态、商户授权变化都会推送，请求体 `{"id","type","createdAt","workspaceNo","data"}`，签名方式同进件回调，按 `X-KPay-Event-ID` 去重。旗下订单、退款、机构结算用 `GET /pay/api/service-provider/orders|refunds|settlements` 查，某天的对账单 CSV 用 `GET /pay/api/service-provider/statements/daily?date=YYYY-MM-DD` 下载。
