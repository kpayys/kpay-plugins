# 其他支持易支付的系统

KPay 兼容易支付（EPay V1、MD5 签名）协议。任何系统只要支持「易支付」「彩虹易支付」「EPay」这类支付方式，一般都能直接对接。

## 通用填法

| 系统里的字段 | 填什么 |
|---|---|
| 接口地址 / 网关地址 / API 地址 | `https://api.kaipay.cn/`。系统要求填完整提交地址的，填 `https://api.kaipay.cn/submit.php`；要求不带结尾 `/` 的，填 `https://api.kaipay.cn` |
| 商户ID / PID / 合作者身份 | KPay「EPay 接入 → EPay 配置」里的商户 ID |
| 商户密钥 / KEY / 通信密钥 | 创建 EPay 兼容密钥时显示的 EPay Key |
| 签名方式 | MD5 |
| 支付类型 | 支付宝 `alipay`、微信 `wxpay`、云闪付 `unionpay`；留空或填其他值时，付款人会在 KPay 收银台里自己选 |

KPay 同时提供这些地址，常见的易支付路径都能用：

```
https://api.kaipay.cn/submit.php     页面跳转下单（也可以用 /epay/submit）
https://api.kaipay.cn/mapi.php       服务端下单（也可以用 /epay/mapi）
https://api.kaipay.cn/api.php        查询订单（也可以用 /epay/api）
```

记得把系统的域名加进 EPay 密钥的授权域名并完成验证，见 [KPay 准备](../kpay-setup.md)。

## 支持和不支持的功能

| 功能 | KPay |
|---|---|
| 页面跳转下单 `submit.php` | 支持 |
| 服务端下单 `mapi.php`（返回 `payurl` / `qrcode`） | 支持 |
| 异步通知、同步跳转（GET 带签名） | 支持，处理成功要返回纯文本 `success` |
| 查询订单 `api.php?act=order` | 支持 |
| 查询商户 `api.php?act=query` | 支持 |
| 退款 `api.php?act=refund` | **不支持**，用 [平台 API 退款](../protocol.md#退款可选) 或在 KPay 后台退款 |
| `clientip`、`sitename`、`param` 等扩展参数 | 可以带，会一并验签；KPay 不使用它们，通知里也不回传 `param` |
| QQ 钱包、京东支付等 | 不支持 |

## 已知自带易支付的其他系统

下面这些系统也带有「易支付」支付方式，按上面的通用填法配置即可。它们大多是闭源或商业程序，这里没有逐一核对后台界面，字段名可能略有不同：

- WordPress 主题 / 插件：子比主题（Zibll）、B2 主题、RiPro 系列、Erphpdown
- 发卡 / 商城：彩虹云商城、彩虹发卡、各类「彩虹」系二开程序
- AI 中转：VoAPI 等基于 One API / NewAPI 的二开面板

有问题可以对照本仓库的 [协议速查](../protocol.md) 排查。

## 要做深度集成

协议细节见 [协议速查](../protocol.md)。也可以直接参考本仓库里现成的插件代码。
