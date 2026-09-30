# ShopXO × KPay

ShopXO 商城用 KPay 收款：下单后跳到 KPay 付款页，支持支付宝、微信支付、云闪付，支持在 ShopXO 后台原路退款。

- 适用版本：ShopXO 6.x
- 安装包：`kpay-shopxo.zip`

## 开始前

按 [KPay 准备](../docs/kpay-setup.md) 拿到商户ID和 EPay Key。创建 EPay 密钥时，**授权域名**填商城的域名。

## 1. 上传插件

二选一：

- 解压到网站根目录，得到 `extend/payment/Kpay.php`；
- 或者在后台 **网站 → 支付方式**，用 **上传** 功能上传 `Kpay.php`。

```
网站根目录/
└── extend/payment/
    └── Kpay.php          ← 文件名和类名都是 Kpay，不要改
```

## 2. 安装和配置

1. 后台 **网站 → 支付方式**，找到 **KPay 凯付**，点 **安装**。
2. 点 **编辑**：

   | 配置项 | 填什么 |
   |---|---|
   | 接口地址 | `https://api.kaipay.cn/` |
   | 商户ID | KPay「EPay 接入 → EPay 配置」里的商户 ID |
   | 商户密钥 | 创建 EPay 兼容密钥时显示的 EPay Key |
   | 支付方式 | 默认「KPay 收银台」，付款人自己选；也可以固定为支付宝 / 微信 / 云闪付 |
   | 退款 API Key / Secret | 选填，填了才能在后台原路退款，见 [KPay 准备](../docs/kpay-setup.md#可选退款用的-api-key) |

   适用终端按需勾选（电脑、H5、微信内、支付宝内都支持）。
3. 保存后 **启用**。启用时 ShopXO 会在网站根目录生成 `payment_order_kpay_notify.php` 和 `payment_order_kpay_respond.php` 两个入口文件，网站根目录要可写。

## 3. 测试

前台下一单，选 KPay 付款，付款完成后回到商城，订单变成「待发货」（虚拟商品按商品设置自动处理）即可。

## 常见问题

**支付方式列表里看不到 KPay**
确认文件放在 `extend/payment/Kpay.php`，文件名首字母大写。

**付款后订单还是待支付**
检查根目录下的 `payment_order_kpay_notify.php` 是否存在、能否被外网访问；后台的支付日志、支付请求日志里可以看到每次下单和通知的记录。

更多问题见 [常见问题](../docs/faq.md)。
