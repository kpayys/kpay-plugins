# KPay 对接插件

把 [KPay 凯付](https://kaipay.cn) 接进常见的发卡、商城、建站、IDC、AI 中转、面板和聚合支付系统；自己开发的系统用 [SDK](sdk/README.md) 接入。免费、开源，下载即用。

支持的付款方式：支付宝、微信支付、云闪付。

## 三步接入

1. **在 KPay 准备商户ID和 EPay Key**，并验证你网站的域名 → [KPay 准备](docs/kpay-setup.md)（所有系统都要先做这一步）
2. **在下表找到你的系统**，按对应说明安装或配置
3. **付一笔小额订单测试**，确认订单自动变成已支付

## 选择你的系统

### 需要安装插件

| 系统 | 能做什么 | 说明 | 安装包 |
|---|---|---|---|
| 异次元发卡（acg-faka） | 商品下单、余额充值 | [安装说明](acg-faka/README.md) | `kpay-acg-faka.zip` |
| 彩虹易支付 | 上游通道、后台退款；**服务商模式**：下级商户进件、绑定收款、分润、费率调整、投诉 | [安装说明](epay/README.md) | `kpay-epay.zip` |
| 超级支付系统 | 直连渠道、退款、查单 | [安装说明](superpay/README.md) | `kpay-superpay.zip` |
| WHMCS | 账单支付、退款、非人民币币种换算 | [安装说明](whmcs/README.md) | `kpay-whmcs.zip` |
| WooCommerce（WordPress） | 结账收款、退款，支持区块结账和 HPOS | [安装说明](woocommerce/README.md) | `kpay-for-woocommerce.zip` |
| 智简魔方 V10 | 收银台收款、原路退款 | [安装说明](zjmf/README.md) | `kpay-zjmf.zip` |
| ShopXO | 商城下单收款、原路退款 | [安装说明](shopxo/README.md) | `kpay-shopxo.zip` |
| Paymenter | 主机计费账单收款（人民币） | [安装说明](paymenter/README.md) | `kpay-paymenter.zip` |

安装包在 [Releases](../../releases) 下载；也可以克隆本仓库，把对应目录复制到你的网站。

### 自带易支付，直接填参数

这些系统自带「易支付」支付方式，不用装插件，填上 KPay 的参数即可：

| 系统 | 说明 |
|---|---|
| NewAPI（及 Veloera 等分支） | [配置说明](docs/direct/newapi.md) |
| OneHub | [配置说明](docs/direct/onehub.md) |
| V2Board / Xboard | [配置说明](docs/direct/v2board.md) |
| 独角数卡（dujiaoka） | [配置说明](docs/direct/dujiaoka.md) |
| Sub2API | [配置说明](docs/direct/sub2api.md) |
| SSPanel-UIM | [配置说明](docs/direct/sspanel.md) |
| 其他支持易支付的系统（子比主题、B2、RiPro、彩虹云商城等） | [通用填法](docs/direct/generic.md) |

### 自己开发的系统：用 SDK

PHP、Python、Node.js、Go 各一份单文件 SDK，无第三方依赖：生成付款地址、服务端下单、校验通知、查单、退款。→ [SDK 说明](sdk/README.md)

不管哪种方式，接口地址都是 `https://api.kaipay.cn/`。

## 遇到问题

- 按报错文字查：[常见问题](docs/faq.md)
- 最常见的三个坑：
  1. **EPay Key 只在创建时显示一次**，在「API 密钥」页，不在「EPay 配置」页；丢了只能重置。
  2. **授权域名必须验证通过**才能下单，而且要填接收支付通知的那个网站域名。
  3. **通知地址要能被外网访问**，CDN、防火墙不要拦截。

## 开发者

- [协议速查](docs/protocol.md)：签名算法、下单和通知参数、HMAC 退款、服务商进件接口
- 测试：`php tests/run.php`，不需要安装依赖（超级支付、Paymenter 部分需要 PHP 8）；SDK 各自目录下有测试
- 打包：`bash scripts/package.sh`，输出到 `dist/`；推送 `v*` 标签会自动发布 Release
- 目录结构：

  ```
  acg-faka/      异次元发卡插件
  epay/          彩虹易支付通道插件 + 服务商模块
  superpay/      超级支付系统接口
  whmcs/         WHMCS 支付网关模块
  woocommerce/   WooCommerce 插件
  zjmf/          智简魔方 V10 插件
  shopxo/        ShopXO 支付插件
  paymenter/     Paymenter 网关扩展
  sdk/           PHP / Python / Node.js / Go SDK
  docs/          KPay 准备、直连指南、常见问题、协议速查
  tests/         无依赖测试
  ```

## 许可

[MIT](LICENSE)
