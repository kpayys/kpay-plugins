-- 帝国CMS × KPay：添加支付接口
-- 在帝国CMS 后台「系统 → 备份与恢复数据 → 执行SQL语句」里执行。
-- 表前缀默认是 phome_，安装时改过前缀的，把下面的 phome_ 换成你的前缀。

INSERT INTO `phome_enewspayapi` (`paytype`, `myorder`, `payfee`, `payuser`, `partner`, `paykey`, `paylogo`, `paysay`, `payname`, `isclose`, `payemail`, `paymethod`)
VALUES ('kpay', 0, '0', '', '', '', '', 'KPay 凯付：支持支付宝、微信支付、云闪付。', 'KPay 凯付', 0, '', 0);
