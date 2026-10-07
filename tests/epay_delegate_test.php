<?php
/**
 * 彩虹易支付：服务商代收（V3 + X-KPay-Sub-Merchant）、事件推送、交易与对账（由 epay_test.php 引入）
 */

check(KpayService::delegatedBindingId('sp:128') === 128, 'delegate: 解析子通道里的渠道商户ID');
check(KpayService::delegatedBindingId('128') === 0 && KpayService::delegatedBindingId('sp:x') === 0 && KpayService::delegatedBindingId('sp:0') === 0, 'delegate: 普通商户ID不是代收');
check(KpayService::paymentAuthStatus(['status' => 'active', 'scopes' => ['complaint']]) === 'none', 'delegate: 只授权了投诉不算代收');
check(KpayService::paymentAuthStatus(['status' => 'active', 'scopes' => ['payment', 'complaint']]) === 'active', 'delegate: 授权了代收');
check(KpayService::paymentAuthStatus(null) === 'none', 'delegate: 没有授权记录');

check(KpayClient::checkoutUrl('https://api.kaipay.cn/', '/checkout/cs_1') === 'https://pay.kaipay.cn/checkout/cs_1', 'delegate: api 域名换成收银台域名');
check(KpayClient::checkoutUrl('https://hk-api.kaipay.cn', '/checkout/cs_1') === 'https://hk-pay.kaipay.cn/checkout/cs_1', 'delegate: 带前缀的 api 域名');
check(KpayClient::checkoutUrl('https://kpay.example.com:8443/', '/checkout/cs_1') === 'https://kpay.example.com:8443/checkout/cs_1', 'delegate: 自定义网关保留原域名和端口');
check(KpayClient::checkoutUrl('https://api.kaipay.cn/', 'https://pay.kaipay.cn/checkout/cs_2') === 'https://pay.kaipay.cn/checkout/cs_2', 'delegate: 已是完整地址时原样使用');
check(KpayClient::checkoutUrl('https://api.kaipay.cn/', '//evil.example.com/x') === '' && KpayClient::checkoutUrl('https://api.kaipay.cn/', 'javascript:alert(1)') === ''
	&& KpayClient::checkoutUrl('https://api.kaipay.cn/', 'http://pay.kaipay.cn/x') === '', 'delegate: 拒绝协议相对、脚本和非 https 地址');

$DB = new FakeDB();
$DB->tables['channel'][3] = ['id' => 3, 'name' => 'KPay 微信', 'plugin' => 'kpay', 'config' => '{"appurl":"https://api.kaipay.cn/","appid":"[appid]","appkey":"[appkey]"}'];
$DB->tables['config']['kpay_sp'] = ['k' => 'kpay_sp', 'v' => json_encode(array_merge(KpayService::defaultSettings(), [
	'apikey' => 'ak_sp', 'apisecret' => 'sk_sp', 'providers' => ['fuyou'], 'callback_channel' => 3,
	'bind_channels' => [3], 'self_service' => 1, 'user_bind' => 1, 'callback_secret' => 'cbsecret', 'webhook_secret' => 'whsec',
	'webhook_url' => 'https://pay.example.com/pay/spnotify/3/',
]))];
$siteurl = 'https://pay.example.com/';
$conf = ['localurl' => 'https://notify.example.com/'];
$service = new TestKpayService();
KpayService::setInstance($service);
$service->install();
check($service->setting('bind_mode') === 'delegate', 'delegate: 默认用服务商代收');
check($service->webhookUrl() === 'https://notify.example.com/pay/spnotify/3/', 'delegate: 事件推送地址指向 KPay 通道');

$DB->insert('kpay_apply', ['uid' => 21, 'external_no' => 'EPAY21', 'application_no' => 'AO21', 'status' => 'approved', 'merchant_code' => 'M555', 'addtime' => 'NOW()', 'updatetime' => 'NOW()']);
$DB->insert('kpay_apply', ['uid' => 22, 'external_no' => 'EPAY22', 'application_no' => 'AO22', 'status' => 'approved', 'merchant_code' => '', 'addtime' => 'NOW()', 'updatetime' => 'NOW()']);
$row = $service->latestForUser(21);

function kpay_test_sub($uid)
{
	global $DB;
	return $DB->getRow("SELECT * FROM pre_subchannel WHERE channel=:c AND uid=:u LIMIT 1", [':c' => 3, ':u' => $uid]);
}

FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants', ['code' => 0, 'data' => ['list' => [
	['id' => 127, 'merchantCode' => 'M5550'], ['id' => 128, 'merchantCode' => 'M555'],
]]]);
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants/128', ['code' => 0, 'data' => ['id' => 128, 'merchantId' => 5021, 'merchantName' => '示例便利店', 'status' => 'active']]);
FakeKpayClient::respond('POST', '/pay/api/service-provider/merchants/128/authorization', ['code' => 0, 'data' => ['id' => 9, 'bindingId' => 128, 'status' => 'pending', 'scopes' => ['payment']]]);
check($service->bindDelegated($row) === 'pending', 'delegate: 绑定后等待商户同意');
check(FakeKpayClient::$requests[0]['uri'] === '/pay/api/service-provider/merchants?keyword=M555&pageSize=20', 'delegate: 按平台商户号查渠道商户');
$authRequest = FakeKpayClient::last();
$authBody = json_decode($authRequest['body'], true);
check($authBody['scopes'] === ['payment'] && strpos($authBody['note'], '本站') !== false, 'delegate: 只申请代收款范围');
check(hmac_request_valid($authRequest, 'sk_sp'), 'delegate: 授权申请签名正确');
$sub = kpay_test_sub(21);
check(json_decode($sub['info'], true) === ['appid' => 'sp:128', 'appkey' => 'sp'] && $sub['status'] === 0, 'delegate: 子通道先停用，商户ID写成 sp:渠道商户ID');
$row = $service->latestForUser(21);
check((int)$row['sp_binding_id'] === 128 && $row['auth_status'] === 'pending' && $row['bind_pid'] === 'sp:128', 'delegate: 本地记录渠道商户和授权状态');

check(throws(function () use ($service) { $service->bindDelegated($service->latestForUser(22), 128); }) === '这个渠道商户已经绑定给 UID 21，不能重复绑定', 'delegate: 同一渠道商户不能绑给两个易支付商户');
FakeKpayClient::reset();
check(strpos((string)throws(function () use ($service) { $service->bindDelegated($service->latestForUser(22)); }), '还没找到这个商户的 KPay 账户') === 0, 'delegate: 没有商户号时提示手动填写');
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants/300', ['code' => 0, 'data' => ['id' => 300, 'status' => 'active']]);
check(throws(function () use ($service) { $service->bindDelegated($service->latestForUser(22), 300); }) === '这个商户还没有开通收款，开通后再绑定', 'delegate: 未开通收款的商户不能绑定');

// 已经授权过：不重复申请，子通道直接启用
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants/129', ['code' => 0, 'data' => ['id' => 129, 'merchantId' => 5022, 'status' => 'active',
	'authorization' => ['status' => 'active', 'scopes' => ['payment', 'complaint']]]]);
check($service->bindDelegated($service->latestForUser(22), 129) === 'active', 'delegate: 已授权的商户直接可用');
check(count(FakeKpayClient::$requests) === 1, 'delegate: 已授权时不再发申请');
check(kpay_test_sub(22)['status'] === 1, 'delegate: 已授权的子通道启用');

// 事件推送：商户同意 → 启用；撤回 → 停用
$event = json_encode(['id' => 'merchant.authorization_changed:9:active', 'type' => 'merchant.authorization_changed', 'data' => ['bindingId' => 128, 'status' => 'active', 'scopes' => ['payment']]]);
fake_kpay_push('whsec', 'merchant.authorization_changed', $event);
check(kpay_plugin::spnotify() === ['type' => 'html', 'data' => 'success'], 'webhook: 验签通过');
check(kpay_test_sub(21)['status'] === 1 && $service->latestForUser(21)['auth_status'] === 'active', 'webhook: 商户同意后子通道启用');
$revoked = json_encode(['id' => 'merchant.authorization_changed:9:revoked', 'type' => 'merchant.authorization_changed', 'data' => ['bindingId' => 128, 'status' => 'revoked', 'scopes' => ['payment']]]);
fake_kpay_push('wrong', 'merchant.authorization_changed', $revoked);
check(kpay_plugin::spnotify()['data'] === 'sign error', 'webhook: 签名不对时拒绝');
check(kpay_test_sub(21)['status'] === 1, 'webhook: 验签失败不改状态');
fake_kpay_push('whsec', 'merchant.authorization_changed', $revoked, time() - 3600);
check(kpay_plugin::spnotify()['data'] === 'sign error', 'webhook: 过期推送被拒');
fake_kpay_push('whsec', 'merchant.authorization_changed', $revoked);
kpay_plugin::spnotify();
check(kpay_test_sub(21)['status'] === 0, 'webhook: 商户撤回后子通道停用');
$statusEvent = json_encode(['type' => 'onboarding.status_changed', 'data' => ['applicationNo' => 'AO22', 'status' => 'rejected', 'reviewRemark' => '执照不清晰']]);
fake_kpay_push('whsec', 'onboarding.status_changed', $statusEvent);
kpay_plugin::spnotify();
$row22 = $service->latestForUser(22);
check($row22['status'] === 'rejected' && $row22['review_remark'] === '执照不清晰', 'webhook: 进件状态变化写入本地');
fake_kpay_push_clear();

// 刷新授权：渠道关系停用视为撤回
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants/129', ['code' => 0, 'data' => ['id' => 129, 'status' => 'disabled', 'authorization' => ['status' => 'active', 'scopes' => ['payment']]]]);
check($service->refreshAuthorization($row22) === 'revoked', 'delegate: 渠道商户停用后不能再代收');
check(kpay_test_sub(22)['status'] === 0, 'delegate: 停用后子通道关闭');

// 下单
$GLOBALS['__notified'] = [];
$GLOBALS['__returned'] = [];
$channel = ['appurl' => 'https://api.kaipay.cn/', 'appid' => 'sp:128', 'appkey' => 'sp', 'appswitch' => '0'];
$order = ['trade_no' => TRADE_NO, 'name' => "会员\n充值", 'realmoney' => '58.00', 'typename' => 'wxpay', 'status' => 0, 'api_trade_no' => ''];
$ordername = '';
FakeKpayClient::reset();
FakeKpayClient::respond('POST', '/pay/api/v3/order/create', ['code' => 0, 'data' => ['orderNo' => 'PC1', 'checkoutUrl' => '/checkout/cs_1', 'checkoutReady' => true]]);
$result = kpay_plugin::submit();
check($result === ['type' => 'jump', 'url' => 'https://pay.kaipay.cn/checkout/cs_1'], 'delegate: 下单后跳转 KPay 收银台');
$create = FakeKpayClient::last();
$sent = json_decode($create['body'], true);
check($create['uri'] === '/pay/api/v3/order/create' && $create['headers']['x-kpay-sub-merchant'] === '128', 'delegate: V3 下单带渠道商户头');
check(hmac_request_valid($create, 'sk_sp') && $create['headers']['x-api-key'] === 'ak_sp', 'delegate: 用服务商密钥签名');
check($sent['merchantOrderNo'] === TRADE_NO && $sent['amount'] == 58 && $sent['paymentMode'] === 'checkout' && $sent['productName'] === '会员 充值', 'delegate: 下单参数');
check($sent['notifyUrl'] === 'https://notify.example.com/pay/notify/' . TRADE_NO . '/' && $sent['returnUrl'] === 'https://pay.example.com/pay/return/' . TRADE_NO . '/', 'delegate: 通知走回调专用网址，回跳走站点地址');
check(!isset($sent['payer']) && !isset($sent['selectedMerchantId']), 'delegate: 不传付款人和商户（由授权锁定）');
check(strpos(end($DB->execLog), 'UPDATE pre_order SET api_trade_no') === 0, 'delegate: 记下 KPay 订单号');

FakeKpayClient::respond('POST', '/pay/api/v3/order/create', ['code' => 0, 'data' => ['orderNo' => 'PC1', 'checkoutUrl' => '/checkout/cs_1', 'checkoutReady' => true]]);
check(kpay_plugin::mapi() === ['type' => 'jump', 'url' => 'https://pay.kaipay.cn/checkout/cs_1'], 'delegate: mapi 同样返回收银台地址');
FakeKpayClient::respond('POST', '/pay/api/v3/order/create', ['code' => 0, 'data' => ['orderNo' => 'PC1', 'checkoutReady' => false, 'warning' => '收银台会话暂不可用']]);
check(kpay_plugin::submit() === ['type' => 'error', 'msg' => '收银台会话暂不可用'], 'delegate: 收银台不可用时报错');
FakeKpayClient::respond('POST', '/pay/api/v3/order/create', ['code' => 7, 'msg' => '该商户不存在、未授权或授权已撤回']);
check(kpay_plugin::submit()['msg'] === '该商户不存在、未授权或授权已撤回', 'delegate: 授权撤回后下单原样报错');

// 支付通知
$paid = json_encode(['orderNo' => 'PC1', 'merchantOrderNo' => TRADE_NO, 'amount' => 58, 'status' => 'paid', 'apiVersion' => 'v3', 'eventType' => 'payment.order.paid']);
fake_kpay_push('sk_sp', 'payment.order.paid', $paid);
check(kpay_plugin::notify() === ['type' => 'html', 'data' => 'success'] && $GLOBALS['__notified'] === ['PC1'], 'delegate: 合法通知入账');
fake_kpay_push('other', 'payment.order.paid', $paid);
check(kpay_plugin::notify()['data'] === 'fail', 'delegate: 别的密钥签的通知被拒');
fake_kpay_push('sk_sp', 'payment.order.paid', str_replace('"amount":58', '"amount":0.01', $paid));
check(kpay_plugin::notify()['data'] === 'fail', 'delegate: 金额不符不入账');
fake_kpay_push('sk_sp', 'payment.order.paid', str_replace(TRADE_NO, '999', $paid));
check(kpay_plugin::notify()['data'] === 'fail', 'delegate: 别的订单的通知不入账');
fake_kpay_push('sk_sp', 'payment.order.closed', str_replace('"status":"paid"', '"status":"closed"', $paid));
check(kpay_plugin::notify()['data'] === 'success', 'delegate: 非支付成功的通知只确认收到');
check($GLOBALS['__notified'] === ['PC1'], 'delegate: 只有一次入账');
fake_kpay_push_clear();

// 回跳
$order['api_trade_no'] = 'PC1';
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/v3/order/query', ['code' => 0, 'data' => ['orderNo' => 'PC1', 'merchantOrderNo' => TRADE_NO, 'amount' => 58, 'status' => 'requires_action']]);
check(kpay_plugin::return() === ['type' => 'error', 'msg' => '订单尚未支付完成'], 'delegate: 未支付时回跳不入账');
check(FakeKpayClient::last()['uri'] === '/pay/api/v3/order/query?orderNo=PC1' && FakeKpayClient::last()['headers']['x-kpay-sub-merchant'] === '128', 'delegate: 回跳时查单带渠道商户头');
FakeKpayClient::respond('GET', '/pay/api/v3/order/query', ['code' => 0, 'data' => ['orderNo' => 'PC1', 'merchantOrderNo' => TRADE_NO, 'amount' => 58, 'status' => 'succeeded']]);
kpay_plugin::return();
check($GLOBALS['__returned'] === ['PC1'], 'delegate: 查到已支付后回跳');
$order['status'] = 1;
FakeKpayClient::reset();
kpay_plugin::return();
check(count(FakeKpayClient::$requests) === 0 && count($GLOBALS['__returned']) === 2, 'delegate: 已入账的订单回跳不再查单');

// 退款
FakeKpayClient::reset();
FakeKpayClient::respond('POST', '/pay/api/v3/order/refund', ['code' => 0, 'data' => ['apiVersion' => 'v3']]);
check(kpay_plugin::refund(['trade_no' => TRADE_NO, 'api_trade_no' => 'PC1', 'refundmoney' => '10.00']) === ['code' => 0], 'delegate: 代收订单可以退款');
$refundReq = FakeKpayClient::last();
$refundBody = json_decode($refundReq['body'], true);
check($refundReq['headers']['x-kpay-sub-merchant'] === '128' && $refundBody['orderNo'] === 'PC1' && $refundBody['refundRequestNo'] === 'R' . TRADE_NO && $refundBody['refundAmount'] == 10, 'delegate: 退款走 V3 并带渠道商户头');
check(hmac_request_valid($refundReq, 'sk_sp'), 'delegate: 退款签名正确');

// 普通通道：主通道里没被替换的 [appmchid] 占位不当成退款 Key
$channel = ['appurl' => 'https://api.kaipay.cn/', 'appid' => '1001', 'appkey' => 'k', 'appmchid' => '[appmchid]', 'appsecret' => '[appsecret]'];
check(kpay_plugin::refund(['trade_no' => TRADE_NO, 'api_trade_no' => 'P1', 'refundmoney' => '1.00'])['code'] === -1, 'plugin: 退款 Key 是占位时按未配置处理');

// 事件推送开关：推送地址已在别处时要确认
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/webhook', ['code' => 0, 'data' => ['configured' => true, 'url' => 'https://erp.example.com/hook']]);
$result = $service->enableWebhook(false);
check($result['configured'] === false && $result['currentUrl'] === 'https://erp.example.com/hook' && count(FakeKpayClient::$requests) === 1, 'webhook: 已推到别处时先确认，不改配置');
FakeKpayClient::respond('GET', '/pay/api/service-provider/webhook', ['code' => 0, 'data' => ['configured' => true, 'url' => 'https://erp.example.com/hook']]);
FakeKpayClient::respond('PUT', '/pay/api/service-provider/webhook', ['code' => 0, 'data' => ['configured' => true, 'url' => 'https://notify.example.com/pay/spnotify/3/']]);
FakeKpayClient::respond('POST', '/pay/api/service-provider/webhook/rotate-secret', ['code' => 0, 'data' => ['secret' => 'whsec_new']]);
check($service->enableWebhook(true)['configured'] === true, 'webhook: 确认后改成本站');
$put = FakeKpayClient::$requests[2];
check(json_decode($put['body'], true) === ['url' => 'https://notify.example.com/pay/spnotify/3/', 'events' => KpayService::WEBHOOK_EVENTS, 'status' => 'active'], 'webhook: 只订阅本站处理的事件');
check($service->setting('webhook_secret') === 'whsec_new' && $service->setting('webhook_url') === 'https://notify.example.com/pay/spnotify/3/', 'webhook: 地址改了就重置并保存签名密钥');
check(json_decode($DB->tables['config']['kpay_sp']['v'], true)['webhook_secret'] === 'whsec_new', 'webhook: 签名密钥写入设置');
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/webhook', ['code' => 0, 'data' => ['configured' => true, 'url' => 'https://notify.example.com/pay/spnotify/3/']]);
FakeKpayClient::respond('PUT', '/pay/api/service-provider/webhook', ['code' => 0, 'data' => ['configured' => true, 'url' => 'https://notify.example.com/pay/spnotify/3/']]);
$service->enableWebhook(false);
check(count(FakeKpayClient::$requests) === 2 && $service->setting('webhook_secret') === 'whsec_new', 'webhook: 已是本站且有密钥时不重置密钥');

// 交易与对账
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/orders', ['code' => 0, 'data' => ['list' => [], 'total' => 0]]);
$service->serviceProvider('orders', ['startTime' => '2026-10-01', 'bindingId' => '128', 'pageSize' => '50', 'resource' => 'x', 'csrf' => 'y']);
check(FakeKpayClient::last()['uri'] === '/pay/api/service-provider/orders?pageSize=50&startTime=2026-10-01&bindingId=128', 'trades: 订单查询只带白名单参数');
$csv = chr(0xEF) . chr(0xBB) . chr(0xBF) . "类型,时间\n订单,2026-10-06\n";
FakeKpayClient::respond('GET', '/pay/api/service-provider/statements/daily', ['__raw' => $csv]);
check($service->dailyStatement('2026-10-06') === $csv, 'trades: 对账单原样返回 CSV');
check(FakeKpayClient::last()['uri'] === '/pay/api/service-provider/statements/daily?date=2026-10-06' && hmac_request_valid(FakeKpayClient::last(), 'sk_sp'), 'trades: 对账单请求签名正确');
FakeKpayClient::respond('GET', '/pay/api/service-provider/statements/daily', ['code' => 7, 'msg' => '没有查看财务的权限']);
check(throws(function () use ($service) { $service->dailyStatement('2026-10-06'); }) === '没有查看财务的权限', 'trades: 对账单接口报错原样提示');
check(throws(function () use ($service) { $service->dailyStatement('2026-10-06&x=1'); }) === '日期格式应为 YYYY-MM-DD', 'trades: 对账日期校验');

// 商户端：代收模式下不能写验证文件，也不能自己指定渠道商户ID
check(throws(function () use ($service) { kpay_apply_action($service, 'verify_domain', 21, false); }) === '不支持的操作', 'ajax: 代收模式下商户不能写验证文件');
$_POST = ['bindingId' => '129'];
$DB->update('kpay_apply', ['sp_binding_id' => null, 'auth_status' => null], ['uid' => 21]);
FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants', ['code' => 0, 'data' => ['list' => [['id' => 128, 'merchantCode' => 'M555']]]]);
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants/128', ['code' => 0, 'data' => ['id' => 128, 'merchantId' => 5021, 'status' => 'active', 'authorization' => ['status' => 'pending', 'scopes' => ['payment']]]]);
FakeKpayClient::respond('GET', '/pay/api/onboarding/applications/AO21', ['code' => 0, 'data' => ['application' => ['applicationNo' => 'AO21', 'status' => 'approved'], 'diagnostics' => ['editable' => false]]]);
$detail = kpay_apply_action($service, 'bind', 21, false);
check(strpos(FakeKpayClient::$requests[0]['uri'], 'keyword=M555') !== false, 'ajax: 商户自助绑定忽略传入的渠道商户ID');
check($detail['data']['apply']['delegated'] === true && $detail['data']['apply']['authStatus'] === 'pending' && $detail['data']['bindMode'] === 'delegate', 'ajax: 商户看到等待授权');
check(!isset($detail['data']['apply']['applicationNo']), 'ajax: 商户看不到进件单号');
$_POST = [];

// 老版本数据表升级
$DB = new FakeDB();
$DB->tables['config']['kpay_sp_db'] = ['k' => 'kpay_sp_db', 'v' => '1'];
(new TestKpayService())->install();
check(strpos(implode("\n", $DB->execLog), 'ADD COLUMN `sp_binding_id`') !== false && $DB->tables['config']['kpay_sp_db']['v'] === '2', 'install: v1 升级到 v2 补上代收字段');
