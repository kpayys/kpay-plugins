# SSPanel-UIM × KPay

SSPanel-UIM 自带 EPay 支付网关，直接填 KPay 的参数就能用，不用装插件。

## 开始前

按 [KPay 准备](../kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填面板的域名（`.config.php` 里 `baseUrl` 的域名）。

## 配置

1. 管理后台进入系统设置里的 **账单**（Billing）设置页，地址是 `https://你的面板/admin/setting/billing`。
2. **网关选择** 标签页：启用 **EPay**。
3. **EPay** 标签页：

   | 字段 | 填什么 |
   |---|---|
   | 网关地址 | `https://api.kaipay.cn/`（**结尾必须带 /**，面板会直接拼 `mapi.php`） |
   | 商户ID | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | 商户Key | 创建 EPay 兼容密钥时显示的 EPay Key |
   | 签名方式 | `MD5` |
   | 支付宝 / 微信支付 | 启用需要的；QQ 钱包、USDT 保持停用（KPay 不支持） |

4. 保存。

## 说明

SSPanel 是服务端调用 KPay 下单的，KPay 拿不到付款人的手机或电脑信息。建议在 KPay「EPay 配置」里保持 **响应模式** 为默认的「收银台」，由 KPay 收银台自动适配手机和电脑。

更多见 [常见问题](../faq.md)。
