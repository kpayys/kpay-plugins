# Sub2API × KPay

Sub2API 自带 EasyPay（易支付）服务商类型，直接填 KPay 的参数就能用，不用装插件。

## 开始前

按 [KPay 准备](../kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填 Sub2API 的站点域名。

## 配置

1. 管理后台 **服务商管理 → 添加服务商**，类型选 **EasyPay（易支付）**。
2. 填写：

   | 字段 | 填什么 |
   |---|---|
   | 商户 ID（PID） | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | 商户密钥（PKey） | 创建 EPay 兼容密钥时显示的 EPay Key |
   | API 地址 | `https://api.kaipay.cn` |
   | 支付宝通道 ID / 微信通道 ID | 留空 |

3. 回到支付设置，把前台的「支付宝」「微信支付」按钮的来源选为刚添加的易支付服务商。

## 说明

- 异步通知和跳转地址由 Sub2API 按站点域名自动生成。
- 退款：Sub2API 的易支付退款走的是 `api.php?act=refund`，KPay 的 EPay 接口不支持这个动作，需要退款时到 KPay 商户后台操作。

更多见 [常见问题](../faq.md)。
