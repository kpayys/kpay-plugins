# WHMCS × KPay

在 WHMCS 里用 KPay 收款：客户在账单页点「立即支付」，跳到 KPay 付款页完成支付，账单自动标记已付。支持在 WHMCS 后台退款。

- 适用版本：WHMCS 8.x
- 安装包：`kpay-whmcs.zip`

## 开始前

按 [KPay 准备](../docs/kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填 WHMCS 的域名（**系统设置 → 常规设置 → 常规** 里「WHMCS System URL」的域名）。

## 1. 上传文件

解压到 WHMCS 根目录，得到：

```
WHMCS 根目录/
└── modules/gateways/
    ├── kpay.php              模块主文件
    ├── kpay/KpayGateway.php  签名和请求
    └── callback/kpay.php     接收 KPay 通知
```

## 2. 启用网关

1. 后台 **系统设置 → 支付 → 支付网关**（英文界面：System Settings → Payment Gateways），在 **所有支付网关** 里点 **KPay 凯付** 启用。
2. 填写配置：

   | 配置项 | 填什么 |
   |---|---|
   | 显示名称 | 客户在账单页看到的名字，比如「支付宝 / 微信支付」 |
   | 接口地址 | `https://api.kaipay.cn/` |
   | 商户ID | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | 商户密钥 | 创建 EPay 兼容密钥时显示的 EPay Key |
   | 支付方式 | 默认「KPay 收银台」，付款人自己选；也可以固定为支付宝 / 微信 / 云闪付 |
   | 订单标题 | 付款页上显示的名称，`{invoiceid}` 会替换成账单号 |
   | 退款 API Key / Secret | 选填，填了才能在 WHMCS 里退款 |

3. **币种**：KPay 只收人民币。WHMCS 默认币种不是 CNY 的，先在 **系统设置 → 币种** 添加 CNY 并设置汇率，再在这个网关的 **Convert To For Processing（转换为以下币种处理）** 里选 CNY。这样账单金额会先换算成人民币再发给 KPay，到账后按原币种全额入账。

## 3. 测试

用测试客户生成一张小额账单，在账单页点支付，付款后刷新账单，状态变成「已付款」即可。后台 **账单 → 网关日志**（Gateway Log）能看到每条 KPay 通知的处理结果。

## 退款

配置了退款 API Key 的，在账单的 **退款** 里选择原路退回即可；没配置的，到 KPay 商户后台退款，再在 WHMCS 里记一笔手动退款。

## 常见问题

**账单页提示「KPay 商户ID或密钥未配置」**
网关配置没保存，或者商户ID、密钥为空。

**付款后账单没变成已付款**
看网关日志：显示「验签失败」多半是密钥填错；没有日志说明通知没到，检查 WHMCS 能否被外网访问、`modules/gateways/callback/kpay.php` 有没有被防火墙拦截。

更多问题见 [常见问题](../docs/faq.md)。
