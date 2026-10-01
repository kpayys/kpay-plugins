# KPay SDK

自己开发的网站、小程序后端、机器人要接 KPay，用这里的 SDK，几十行代码就能收款。四种语言都是单文件、无第三方依赖，接口一致：

| 语言 | 文件 | 要求 |
|---|---|---|
| PHP | [`php/KPay.php`](php/KPay.php) | PHP 7.1+，curl |
| Python | [`python/kpay.py`](python/kpay.py) | Python 3.8+，只用标准库 |
| Node.js | [`node/kpay.mjs`](node/kpay.mjs) | Node 18+ |
| Go | [`go/kpay.go`](go/kpay.go) | Go 1.21+，`go get github.com/kpayys/kpay-plugins/sdk/go` |

## 安装

| 语言 | 方式 |
|---|---|
| PHP | 把 `php/KPay.php` 复制到项目里，`require` 即可 |
| Python | `pip install "git+https://github.com/kpayys/kpay-plugins.git#subdirectory=sdk/python"`，或直接复制 `kpay.py` |
| Node.js | 把 `node/kpay.mjs` 复制到项目里 `import` |
| Go | `go get github.com/kpayys/kpay-plugins/sdk/go` |

## 开始前

按 [KPay 准备](../docs/kpay-setup.md) 拿到商户ID和 EPay Key，并把你网站的域名加进授权域名、完成验证。

## 能做什么

| 功能 | PHP | Python | Node.js | Go |
|---|---|---|---|---|
| 生成跳转付款地址 | `payUrl()` | `pay_url()` | `payUrl()` | `PayURL()` |
| 服务端下单拿付款页 | `createOrder()` | `create_order()` | `createOrder()` | `CreateOrder()` |
| 校验异步通知 | `verifyNotify()` | `verify_notify()` | `verifyNotify()` | `VerifyNotify()` |
| 查询订单 | `queryOrder()` | `query_order()` | `queryOrder()` | `QueryOrder()` |
| 退款（要平台 API 密钥） | `refund()` | `refund()` | `refund()` | `Refund()` |

## 最小示例

### PHP

```php
require 'KPay.php';
$kpay = new \KPay\Client('商户ID', 'EPay Key');

// 下单：把用户跳转到这个地址
header('Location: ' . $kpay->payUrl([
    'out_trade_no' => 'ORDER1001',          // 你的订单号，唯一
    'name'         => '会员月卡',
    'money'        => '9.90',
    'notify_url'   => 'https://你的域名/kpay/notify.php',
    'return_url'   => 'https://你的域名/order/ORDER1001',
]));

// notify.php：KPay 以 GET 方式通知
if ($kpay->verifyNotify($_GET) && $_GET['trade_status'] === 'TRADE_SUCCESS') {
    // 核对 $_GET['out_trade_no'] 对应订单的金额等于 $_GET['money']，再标记已支付（重复通知要幂等）
    echo 'success';
}
```

### Python（Flask）

```python
from flask import Flask, redirect, request
from kpay import KPay

app = Flask(__name__)
kpay = KPay("商户ID", "EPay Key")

@app.route("/buy")
def buy():
    return redirect(kpay.pay_url({"out_trade_no": "ORDER1001", "name": "会员月卡", "money": "9.90",
                                  "notify_url": "https://你的域名/kpay/notify"}))

@app.route("/kpay/notify")
def notify():
    data = request.args.to_dict()
    if kpay.verify_notify(data) and data.get("trade_status") == "TRADE_SUCCESS":
        # 核对金额、幂等入账
        return "success"
    return "fail"
```

### Node.js（Express）

```js
import express from 'express'
import { KPay } from './kpay.mjs'

const app = express()
const kpay = new KPay({ pid: '商户ID', key: 'EPay Key' })

app.get('/buy', (req, res) => {
  res.redirect(kpay.payUrl({ out_trade_no: 'ORDER1001', name: '会员月卡', money: '9.90', notify_url: 'https://你的域名/kpay/notify' }))
})

app.get('/kpay/notify', (req, res) => {
  if (kpay.verifyNotify(req.query) && req.query.trade_status === 'TRADE_SUCCESS') {
    // 核对金额、幂等入账
    return res.send('success')
  }
  res.send('fail')
})
```

### Go

```go
client := kpay.New("商户ID", "EPay Key")

http.HandleFunc("/buy", func(w http.ResponseWriter, r *http.Request) {
	u, _ := client.PayURL(kpay.Order{OutTradeNo: "ORDER1001", Name: "会员月卡", Money: "9.90",
		NotifyURL: "https://你的域名/kpay/notify"})
	http.Redirect(w, r, u, http.StatusFound)
})

http.HandleFunc("/kpay/notify", func(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	if client.VerifyNotify(q) && q.Get("trade_status") == "TRADE_SUCCESS" {
		// 核对金额、幂等入账
		w.Write([]byte("success"))
		return
	}
	w.Write([]byte("fail"))
})
```

## 入账前一定要做的检查

1. `verifyNotify` 通过（签名正确、商户ID是你的）。
2. `trade_status` 等于 `TRADE_SUCCESS`。
3. `money` 等于你订单的金额。
4. 这笔订单还没处理过（同一笔通知可能收到多次）。
5. 处理完返回纯文本 `success`，否则 KPay 会重试。

## 退款

```php
$kpay = new \KPay\Client('商户ID', 'EPay Key', '', 'API Key', 'API Secret');
$kpay->refund('KPay 订单号 trade_no', 9.90, '你的退款单号');   // 重试时退款单号保持不变
```

API Key 是「平台 API」密钥，要勾选「发起退款」权限，见 [KPay 准备](../docs/kpay-setup.md#可选退款用的-api-key)。

协议细节见 [协议速查](../docs/protocol.md)。
