# 智简魔方 × KPay

智简魔方财务系统 V10 用 KPay 收款：客户在收银台选 KPay，点「前往付款」完成支付，订单自动入账。支持原路退款。

- 适用版本：智简魔方财务系统（业务系统）V10
- 安装包：`kpay-zjmf.zip`

## 开始前

按 [KPay 准备](../docs/kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填智简魔方的网站域名（后台系统设置里「网站地址」的域名）。

## 1. 上传插件

解压到网站根目录，得到：

```
网站根目录/
└── public/plugins/gateway/
    └── kpay/              ← 目录名必须是 kpay
        ├── Kpay.php
        ├── Kpay.png
        ├── config.php
        ├── controller/IndexController.php
        └── lib/KpayClient.php
```

## 2. 安装和配置

1. 后台 **接口管理 → 支付接口**，找到 **KPay 凯付**，点 **安装**。
2. 点 **配置**：

   | 配置项 | 填什么 |
   |---|---|
   | 通道名称 | 收银台上显示的名字，比如「支付宝 / 微信支付」 |
   | 接口地址 | `https://api.kaipay.cn/` |
   | 商户ID | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | 商户密钥 | 创建 EPay 兼容密钥时显示的 EPay Key |
   | 支付方式 | 默认「KPay 收银台」，付款人自己选；也可以固定为支付宝 / 微信 / 云闪付 |
   | 退款 API Key / Secret | 选填，填了才能原路退款 |

3. 保存后点 **启用**。

## 3. 测试

前台充值或购买一个小额产品，在收银台选 KPay，点 **前往付款**，付款完成后回到订单页刷新，状态变成已支付即可。

## 回调地址

插件自动使用，不用填写：

- 异步通知：`https://你的网站/gateway/kpay/index/notifyHandle`
- 同步跳转：`https://你的网站/gateway/kpay/index/returnHandle`

## 常见问题

**收银台显示「KPay 商户ID或密钥未配置」**
插件配置没保存，或者商户ID、密钥为空。

**付款后订单没入账**
确认网站能被外网访问，上面的异步通知地址没有被防火墙拦截；在 KPay 订单详情里可以看到通知结果。

更多问题见 [常见问题](../docs/faq.md)。
