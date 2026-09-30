# V2Board / Xboard × KPay

V2Board 和 Xboard 自带的 EPay 支付接口可以直接对接 KPay，不用装插件。

## 开始前

按 [KPay 准备](../kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填面板的域名；在支付方式里另外填了「通知域名」的，也要把通知域名加进去。

## V2Board

1. 管理后台 **支付配置 → 添加支付方式**。
2. 填写：

   | 字段 | 填什么 |
   |---|---|
   | 显示名称 | 用户看到的名字，比如「支付宝」 |
   | 通知域名 | 选填，不填用面板域名 |
   | 接口文件 | 选 **EPay** |
   | URL | `https://api.kaipay.cn`（**结尾不要带 /**，面板会自己拼 `/submit.php`） |
   | PID | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | KEY | 创建 EPay 兼容密钥时显示的 EPay Key |

3. 保存并启用。V2Board 的 EPay 接口不指定付款方式，付款人会在 KPay 收银台里选支付宝或微信。

## Xboard

1. 新版 Xboard 的支付接口是插件：先在 **插件管理** 里安装并启用 **EPay**。
2. **支付配置 → 添加支付方式**，接口选 **EPay**，填写：

   | 字段 | 填什么 |
   |---|---|
   | 支付网关地址 | `https://api.kaipay.cn`（结尾不要带 /） |
   | 商户ID | KPay 商户 ID |
   | 通信密钥 | EPay Key |
   | 支付类型 | `alipay` 或 `wxpay`；留空由付款人在 KPay 收银台选 |

3. 想同时显示支付宝和微信，就添加两个支付方式，支付类型分别填 `alipay`、`wxpay`。

## 测试

用普通用户买一个最便宜的套餐，付款后订单自动开通即可。

更多见 [常见问题](../faq.md)。
