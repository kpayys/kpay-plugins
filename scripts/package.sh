#!/usr/bin/env bash
# 把各插件打成可以直接解压到目标系统的 zip，输出到 dist/
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
dist="$root/dist"
rm -rf "$dist"
mkdir -p "$dist"

# 异次元发卡：解压到 app/Pay/ 下得到 app/Pay/KPay/
(cd "$root/acg-faka" && zip -qr "$dist/kpay-acg-faka.zip" KPay)

# 彩虹易支付：解压到网站根目录，得到 plugins/kpay/、admin/kpay.php、user/kpay.php 等
(cd "$root/epay" && zip -qr "$dist/kpay-epay.zip" plugins admin user)

# 超级支付：解压到网站根目录，得到 php/app/payApi/controller/Kpay.php
(cd "$root/superpay" && zip -qr "$dist/kpay-superpay.zip" php)

(cd "$dist" && sha256sum ./*.zip > SHA256SUMS)
ls -l "$dist"
