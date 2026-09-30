# Paymenter × KPay

[Paymenter](https://paymenter.org)（开源的主机计费系统）用 KPay 收人民币：客户在账单页选 KPay，跳到付款页完成支付，账单自动入账。

- 适用版本：Paymenter 1.x（PHP 8.2+）
- 安装包：`kpay-paymenter.zip`
- 账单币种必须是人民币（CNY）

## 开始前

按 [KPay 准备](../docs/kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填 Paymenter 的域名（`.env` 里 `APP_URL` 的域名）。

## 1. 上传扩展

解压到 Paymenter 根目录，得到：

```
Paymenter 根目录/
└── extensions/Gateways/Kpay/
    ├── Kpay.php
    └── routes.php
```

## 2. 启用网关

1. 管理后台 **Extensions → Gateways**，找到 **KPay**，点 **Enable / Configure**。
2. 填写：

   | 字段 | 填什么 |
   |---|---|
   | API URL | `https://api.kaipay.cn/` |
   | Merchant ID (pid) | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | EPay Key | 创建 EPay 兼容密钥时显示的 EPay Key |
   | Payment method | 默认 KPay checkout（付款人自选），也可以固定为 Alipay / WeChat Pay / UnionPay |

3. 保存。确认 **Settings → Currencies** 里有人民币（CNY），并且要用 KPay 付款的账单是人民币计价；非人民币账单会提示 KPay 只收 CNY。

## 3. 测试

生成一张小额人民币账单，在账单页选 KPay 付款，付款后刷新，账单变成已付款即可。

## 回调地址

扩展自动注册，不用填写：`https://你的域名/extensions/gateways/kpay/notify`（GET）。同一笔 KPay 订单重复通知时，Paymenter 按交易号更新原记录，不会重复入账。

## 常见问题

**账单页没有 KPay**
确认扩展已启用；账单不是人民币时 KPay 会拒绝付款。

**付款后账单没变**
确认上面的回调地址能被外网访问，没有被 Cloudflare 等拦截。

更多问题见 [常见问题](../docs/faq.md)。
