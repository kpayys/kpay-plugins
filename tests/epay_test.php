<?php
/**
 * 彩虹易支付：KPay 客户端、通道插件、服务商进件模块（由 run.php 引入）
 */

define('TRADE_NO', '2026093012345');
define('IN_PLUGIN', true);

$GLOBALS['__notified'] = [];
$GLOBALS['__returned'] = [];

function processNotify($order, $api_trade_no = null)
{
	$GLOBALS['__notified'][] = $api_trade_no;
}

function processReturn($order, $api_trade_no = null)
{
	$GLOBALS['__returned'][] = $api_trade_no;
}

function checkmobile()
{
	return false;
}

$epayRoot = dirname(__DIR__) . '/epay';
require $epayRoot . '/plugins/kpay/kpay_plugin.php';
require $epayRoot . '/plugins/kpay/inc/KpayService.php';
require $epayRoot . '/plugins/kpay/inc/KpayAjax.php';
require __DIR__ . '/fakes.php';

// ─── KpayClient ─────────────────────────────────────────────────────

foreach (VECTOR_CASES as $i => $case) {
	check(KpayClient::epaySign($case['params'], VECTOR_KEY) === $case['sign'], "client: EPay 签名向量 #{$i}");
}

$notifyParams = VECTOR_CASES[2]['params'];
$notifyParams['sign'] = VECTOR_CASES[2]['sign'];
$notifyParams['sign_type'] = 'MD5';
check(KpayClient::epayVerify($notifyParams, '1001', VECTOR_KEY), 'client: EPay 验签通过');
check(!KpayClient::epayVerify($notifyParams, '1002', VECTOR_KEY), 'client: 其他商户的通知被拒');
check(!KpayClient::epayVerify(array_merge($notifyParams, ['money' => '1.00']), '1001', VECTOR_KEY), 'client: 篡改金额被拒');

check(KpayClient::normalizeGateway('') === 'https://api.kaipay.cn/', 'client: 默认网关');
check(KpayClient::normalizeGateway('https://api.kaipay.cn/epay/') === 'https://api.kaipay.cn/', 'client: 去掉 /epay/');
check(throws(function () { KpayClient::normalizeGateway('api.kaipay.cn'); }) !== false, 'client: 非法网关报错');

$hmac = VECTORS['hmac'];
$client = new KpayClient('', 'ak_test', $hmac['secret']);
$headers = $client->signHeaders('POST', $hmac['requestUri'], $hmac['body'], $hmac['timestamp'], $hmac['nonce']);
check(in_array('X-KPay-Signature: ' . $hmac['signature'], $headers, true), 'client: HMAC 签名与服务端规则一致');
check(in_array('X-KPay-Body-SHA256: ' . $hmac['bodySha256'], $headers, true), 'client: 请求体摘要');
check(in_array('X-API-Key: ak_test', $headers, true), 'client: 带 API Key');
$headers = $client->signHeaders('GET', VECTORS['hmacGet']['requestUri'], '', $hmac['timestamp'], $hmac['nonce']);
check(in_array('X-KPay-Signature: ' . VECTORS['hmacGet']['signature'], $headers, true), 'client: GET 带查询串的签名');
check(json_encode(['orderNo' => 'P20260930120000001', 'refundAmount' => 1.5, 'refundRequestNo' => 'R2026093012345', 'reason' => '易支付后台退款'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $hmac['body'], 'client: 退款请求体序列化与向量一致');
check(throws(function () { (new KpayClient())->signHeaders('GET', '/', ''); }) !== false, 'client: 未配置 API Key 时报错');

$cb = VECTORS['callback'];
$cbHeaders = ['x-kpay-timestamp' => $cb['timestamp'], 'x-kpay-nonce' => $cb['nonce'], 'x-kpay-event' => $cb['event'], 'x-kpay-signature' => $cb['signature'], 'x-kpay-body-sha256' => $cb['bodySha256']];
$now = (int)$cb['timestamp'] + 30;
check(KpayClient::verifyCallback($cb['secret'], $cbHeaders, $cb['body'], 600, $now), 'client: 进件回调验签通过');
check(!KpayClient::verifyCallback($cb['secret'], $cbHeaders, $cb['body'] . ' ', 600, $now), 'client: 回调请求体被改动时拒绝');
check(!KpayClient::verifyCallback('wrong', $cbHeaders, $cb['body'], 600, $now), 'client: 回调密钥不对时拒绝');
check(!KpayClient::verifyCallback($cb['secret'], $cbHeaders, $cb['body'], 600, $now + 3600), 'client: 过期回调被拒');
check(!KpayClient::verifyCallback($cb['secret'], array_merge($cbHeaders, ['x-kpay-event' => 'onboarding.application.rejected']), $cb['body'], 600, $now), 'client: 事件名被改动时拒绝');

$multipart = KpayClient::buildMultipart('BOUNDARY', ['materialType' => 'id_card_front', 'actorMode' => 'service_provider'], 'file', 'a"b.jpg', 'image/jpeg', "\xFF\xD8\xFFdata");
check(strpos($multipart, "--BOUNDARY\r\nContent-Disposition: form-data; name=\"materialType\"\r\n\r\nid_card_front\r\n") === 0, 'client: multipart 普通字段');
check(strpos($multipart, "filename=\"a%22b.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n\xFF\xD8\xFFdata\r\n--BOUNDARY--\r\n") !== false, 'client: multipart 文件字段且文件名转义');

// ─── 通道插件 ───────────────────────────────────────────────────────

$siteurl = 'https://pay.example.com/';
$conf = ['localurl' => 'https://pay.example.com/'];
$ordername = '';
$channel = ['appurl' => 'https://api.kaipay.cn/', 'appid' => '1001', 'appkey' => VECTOR_KEY, 'appswitch' => '0'];
$order = ['trade_no' => TRADE_NO, 'name' => '会员 & 充值 = 1', 'realmoney' => '99.90', 'typename' => 'bank'];

check(kpay_plugin::$info['name'] === 'kpay', 'plugin: 插件名与目录名一致');

$result = kpay_plugin::submit();
check($result['type'] === 'html', 'plugin: 跳转模式返回表单');
check(strpos($result['data'], 'action="https://api.kaipay.cn/epay/submit"') !== false, 'plugin: 表单提交到 /epay/submit');
check(strpos($result['data'], 'name="type" value="unionpay"') !== false, 'plugin: bank 映射为 unionpay');
check(strpos($result['data'], 'clientip') === false, 'plugin: 不带 clientip');
check(strpos($result['data'], '会员 &amp; 充值 = 1') !== false, 'plugin: 表单值做了 HTML 转义');

preg_match_all('/name="([^"]+)" value="([^"]*)"/', $result['data'], $m, PREG_SET_ORDER);
$posted = [];
foreach ($m as $pair) {
	$posted[html_entity_decode($pair[1], ENT_QUOTES, 'UTF-8')] = html_entity_decode($pair[2], ENT_QUOTES, 'UTF-8');
}
check(KpayClient::epaySign($posted, VECTOR_KEY) === $posted['sign'], 'plugin: 表单参数签名正确');
check($posted['notify_url'] === 'https://pay.example.com/pay/notify/' . TRADE_NO . '/', 'plugin: 回调地址');

$channel['appswitch'] = '1';
$result = kpay_plugin::submit();
check($result['type'] === 'jump' && $result['url'] === 'https://pay.example.com/pay/bank/' . TRADE_NO . '/', 'plugin: 服务端下单模式跳到支付方式页');
$channel['appswitch'] = '0';

$channel['appid'] = '[appid]';
check(kpay_plugin::submit()['type'] === 'error', 'plugin: 主通道占位没被子通道替换时拒绝下单');
$channel['appid'] = '1001';

$_GET = $notifyParams;
check(kpay_plugin::notify() === ['type' => 'html', 'data' => 'success'], 'plugin: 合法通知返回 success');
check($GLOBALS['__notified'] === ['P20260930120000001'], 'plugin: 合法通知入账并记录平台单号');

$_GET = array_merge($notifyParams, ['money' => '0.01']);
check(kpay_plugin::notify()['data'] === 'fail', 'plugin: 篡改金额被拒');

$_GET = $notifyParams;
$channel['appid'] = '1002';
check(kpay_plugin::notify()['data'] === 'fail', 'plugin: 其他商户的通知被拒');
$channel['appid'] = '1001';

$order['realmoney'] = '100.00';
check(kpay_plugin::notify()['data'] === 'fail', 'plugin: 金额与订单不符不入账');
$order['realmoney'] = '99.90';
check(count($GLOBALS['__notified']) === 1, 'plugin: 失败的通知都没有入账');

$_GET = $notifyParams;
kpay_plugin::return();
check($GLOBALS['__returned'] === ['P20260930120000001'], 'plugin: 同步回跳验签通过');

$refund = kpay_plugin::refund(['trade_no' => TRADE_NO, 'api_trade_no' => 'P20260930120000001', 'refundmoney' => '1.00']);
check($refund['code'] === -1, 'plugin: 未配置退款 Key 时拒绝退款');

// ─── 进件模块：纯函数 ───────────────────────────────────────────────

$settings = array_merge(KpayService::defaultSettings(), [
	'providers' => ['fuyou', 'alipay'],
	'package_code' => 'stable_online',
	'extra_create' => '{"fuyouConfigId":12,"merchantName":"不应覆盖"}',
]);
$remoteProfile = [
	'merchantName' => '旧名称', 'idCardFrontImage' => '/uploads/id.jpg', 'legalName' => '张三', 'addressDetail' => '旧地址',
];
$body = KpayService::buildBody(['merchantName' => ' 新名称 ', 'addressDetail' => '新地址', 'evil' => 'x'], $remoteProfile, $settings, ['huifu']);
check($body['merchantName'] === '新名称', 'service: 表单字段覆盖底稿并去空格');
check($body['idCardFrontImage'] === '/uploads/id.jpg' && $body['legalName'] === '张三', 'service: 改单保留已上传图片和 OCR 字段');
check(!isset($body['evil']), 'service: 表单里的未知字段不会写入');
check($body['fuyouConfigId'] === 12, 'service: 附加参数写入请求体');
check($body['selectedProviders'] === ['huifu'], 'service: 改单沿用原进件单的机构');
check($body['onboardingPackageCode'] === 'stable_online' && $body['actorMode'] === 'service_provider', 'service: 套餐和服务商身份');

$body = KpayService::buildBody(['industryCodePreferred' => '5311'], [], $settings, null);
check($body['selectedProviders'] === ['fuyou', 'alipay'], 'service: 建单使用设置里的机构');
check($body['providerIndustryProfiles']['alipay']['industryCodePreferred'] === '5311', 'service: 选了支付宝时补上支付宝行业编码');

$valid = ['merchantName' => 'a', 'subjectType' => 'personal', 'contactMobile' => '13800138000', 'industryKeyword' => '网店',
	'provinceCode' => '310000', 'cityCode' => '310100', 'countyCode' => '310115', 'addressDetail' => 'x', 'selectedProviders' => ['fuyou']];
check(throws(function () use ($valid) { KpayService::assertRequired($valid); }) === false, 'service: 资料齐全时通过校验');
check(throws(function () use ($valid) { KpayService::assertRequired(array_merge($valid, ['contactMobile' => '123'])); }) === '联系人手机号格式不正确', 'service: 手机号校验');
check(throws(function () use ($valid) { KpayService::assertRequired(array_merge($valid, ['countyCode' => ''])); }) === '请填写经营区县', 'service: 必填项校验');
check(throws(function () use ($valid) { KpayService::assertRequired(array_merge($valid, ['subjectType' => 'x'])); }) === '主体类型不正确', 'service: 主体类型校验');

$view = [
	'application' => ['applicationNo' => 'AO1', 'status' => 'submitted', 'reviewRemark' => ''],
	'commonProfile' => ['merchantName' => '示例', 'legalIdNumber' => '310101199001011234', 'idCardFrontImage' => '/uploads/id.jpg'],
	'materials' => [['materialType' => 'id_card_front', 'originUrl' => '/uploads/id.jpg']],
	'providerStatuses' => [
		['provider' => 'fuyou', 'providerLabel' => '富友', 'remoteMerchantCode' => '0001', 'remoteSignUrl' => 'https://sign.example.com/a'],
		['provider' => 'alipay', 'remoteSignUrl' => 'javascript:alert(1)'],
	],
	'diagnostics' => ['canSubmit' => false, 'editable' => false, 'statusLabel' => '审核中', 'missingMaterials' => [['materialType' => 'store_indoor', 'label' => '店内照', 'uploaded' => false]]],
];
$merchant = KpayService::merchantView($view);
$encoded = json_encode($merchant, JSON_UNESCAPED_UNICODE);
check($merchant['statusLabel'] === '审核中' && $merchant['editable'] === false, 'service: 商户视图状态');
check($merchant['confirmUrls'] === ['https://sign.example.com/a'], 'service: 只透出 https 确认链接');
check(strpos($encoded, '富友') === false && strpos($encoded, 'fuyou') === false && strpos($encoded, '0001') === false, 'service: 商户视图不透出机构和机构商户号');
check(strpos($encoded, 'uploads') === false, 'service: 商户视图不透出图片地址');
check($merchant['uploaded'] === ['id_card_front'] && $merchant['missingMaterials'][0]['label'] === '店内照', 'service: 已传和待传材料');
check($merchant['form']['legalIdNumber'] === '310101199001011234' && !isset($merchant['form']['idCardFrontImage']), 'service: 表单只回填可编辑字段');

$summary = KpayService::summarize($view);
check(!isset($summary['form']) && strpos(json_encode($summary), '310101') === false, 'service: 本地摘要不含证件号');

check(KpayService::detectImageMime("\xFF\xD8\xFF\xE0") === 'image/jpeg', 'service: 识别 jpg');
check(KpayService::detectImageMime("\x89PNG\r\n\x1A\n0000") === 'image/png', 'service: 识别 png');
check(KpayService::detectImageMime("RIFF0000WEBP") === 'image/webp', 'service: 识别 webp');
check(KpayService::detectImageMime('<?php echo 1;') === '', 'service: 拒绝非图片');
check(KpayService::canStartNew(['status' => 'rejected']) && !KpayService::canStartNew(['status' => 'submitted']), 'service: 退回后才能重新申请');
check(preg_match('/^EPAY7T\d{14}[0-9a-f]{6}$/', KpayService::newExternalNo(7)) === 1, 'service: 外部请求号格式');

// ─── 进件模块：完整流程（假数据库 + 假 KPay） ───────────────────────

$DB = new FakeDB();
$DB->tables['channel'][3] = ['id' => 3, 'name' => 'KPay 支付宝', 'plugin' => 'kpay', 'config' => '{"appurl":"https://api.kaipay.cn/","appid":"[appid]","appkey":"[appkey]"}'];
$DB->tables['channel'][4] = ['id' => 4, 'name' => '其他', 'plugin' => 'alipay', 'config' => '{}'];
$DB->tables['config']['kpay_sp'] = ['k' => 'kpay_sp', 'v' => json_encode(array_merge(KpayService::defaultSettings(), [
	'apikey' => 'ak_sp', 'apisecret' => 'sk_sp', 'providers' => ['fuyou'], 'callback_channel' => 3,
	'bind_channels' => [3], 'self_service' => 1, 'user_bind' => 1, 'callback_secret' => 'cbsecret',
]))];

$service = new TestKpayService();
$service->install();
check(isset($DB->tables['config']['kpay_sp_db']), 'flow: 建表并记录版本');
check($service->configured(), 'flow: 设置已生效');
check($service->callbackUrl() === 'https://pay.example.com/pay/applynotify/3/', 'flow: 回调地址指向 KPay 通道');

$form = ['subjectType' => 'personal', 'merchantName' => '示例小店', 'contactMobile' => '13800138000', 'industryKeyword' => '网店',
	'provinceCode' => '310000', 'cityCode' => '310100', 'countyCode' => '310115', 'addressDetail' => '张江路 1 号'];
FakeKpayClient::reset();
FakeKpayClient::respond('POST', '/pay/api/onboarding/applications', ['code' => 0, 'data' => [
	'application' => ['applicationNo' => 'AO1', 'status' => 'draft'],
	'commonProfile' => ['merchantName' => '示例小店'],
	'diagnostics' => ['canSubmit' => false, 'editable' => true, 'progressPercent' => 40, 'missingFields' => ['身份证号']],
]]);
$row = $service->saveDraft(7, $form);
$request = FakeKpayClient::last();
$sent = json_decode($request['body'], true);
check($request['method'] === 'POST' && $request['uri'] === '/pay/api/onboarding/applications', 'flow: 建单请求');
check(hmac_request_valid($request, 'sk_sp'), 'flow: 建单请求签名能通过服务端验签');
check($sent['callbackUrl'] === 'https://pay.example.com/pay/applynotify/3/' && $sent['callbackSecret'] === 'cbsecret', 'flow: 建单带回调地址和密钥');
check(strpos($sent['externalRequestNo'], 'EPAY7T') === 0 && $sent['selectedProviders'] === ['fuyou'], 'flow: 建单带防重号和机构');
check($row['application_no'] === 'AO1' && $row['status'] === 'draft' && $row['merchant_name'] === '示例小店', 'flow: 本地记录进件单');
check(strpos($row['summary'], '身份证号') !== false, 'flow: 本地记录缺失项摘要');

FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/onboarding/applications/AO1', ['code' => 0, 'data' => [
	'application' => ['applicationNo' => 'AO1', 'status' => 'draft'],
	'selectedProviders' => ['fuyou'],
	'commonProfile' => array_merge($form, ['idCardFrontImage' => '/uploads/id.jpg', 'legalName' => '张三']),
	'diagnostics' => ['editable' => true],
]]);
FakeKpayClient::respond('PUT', '/pay/api/onboarding/applications/AO1', ['code' => 0, 'data' => [
	'application' => ['applicationNo' => 'AO1', 'status' => 'draft'],
	'diagnostics' => ['canSubmit' => true, 'editable' => true],
]]);
$service->saveDraft(7, array_merge($form, ['addressDetail' => '张江路 2 号']));
$put = FakeKpayClient::last();
$sent = json_decode($put['body'], true);
check(FakeKpayClient::$requests[0]['uri'] === '/pay/api/onboarding/applications/AO1?actorMode=service_provider', 'flow: 改单前先取回完整资料');
check($put['method'] === 'PUT' && hmac_request_valid($put, 'sk_sp'), 'flow: 改单请求签名正确');
check($sent['addressDetail'] === '张江路 2 号' && $sent['idCardFrontImage'] === '/uploads/id.jpg' && $sent['legalName'] === '张三', 'flow: 改单保留图片和 OCR 字段');
check(!isset($sent['externalRequestNo']), 'flow: 改单不再传防重号');

FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/onboarding/applications/AO1', ['code' => 0, 'data' => [
	'application' => ['applicationNo' => 'AO1', 'status' => 'submitted'],
	'diagnostics' => ['editable' => false],
]]);
check(throws(function () use ($service, $form) { $service->saveDraft(7, $form); }) === '当前进件单不能修改', 'flow: 已提交的进件单不能修改');

FakeKpayClient::reset();
FakeKpayClient::respond('POST', '/pay/api/onboarding/applications', ['code' => 7, 'msg' => 'API密钥验证失败']);
check(throws(function () use ($service, $form) { $service->saveDraft(8, $form); }) === 'API密钥验证失败', 'flow: KPay 报错原样提示');

$service->applyCallback(['applicationNo' => 'AO1', 'status' => 'approved', 'activationStatus' => 'activated', 'merchantCode' => 'M123', 'reviewRemark' => '']);
$row = $service->latestForUser(7);
check($row['status'] === 'approved' && $row['status_label'] === '已通过' && $row['merchant_code'] === 'M123', 'flow: 回调更新本地状态');
check($service->applyCallback(['applicationNo' => 'NOPE']) === false, 'flow: 未知进件单的回调忽略');

FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/epay/api', ['code' => -1, 'msg' => '密钥错误']);
check(throws(function () use ($service, $row) { $service->bind($row, '2001', 'bad'); }) === '商户ID或密钥不正确', 'flow: 绑定前校验凭据');
check(!$DB->tables['subchannel'], 'flow: 凭据错误时不生成子通道');

FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/epay/api', ['code' => 1, 'pid' => 2001]);
check($service->bind($row, '2001', 'goodkey') === 1, 'flow: 绑定生成子通道');
$sub = reset($DB->tables['subchannel']);
check($sub['channel'] === 3 && $sub['uid'] === 7 && $sub['status'] === 1 && json_decode($sub['info'], true) === ['appid' => '2001', 'appkey' => 'goodkey'], 'flow: 子通道参数');
check(strpos(FakeKpayClient::last()['uri'], 'act=query') !== false, 'flow: 用查询接口校验凭据');
FakeKpayClient::respond('GET', '/epay/api', ['code' => 1, 'pid' => 2001]);
$service->bind($row, '2001', 'newkey');
check(count($DB->tables['subchannel']) === 1 && json_decode(reset($DB->tables['subchannel'])['info'], true)['appkey'] === 'newkey', 'flow: 重复绑定更新原子通道');
check($service->latestForUser(7)['bind_pid'] === '2001', 'flow: 记录绑定的商户ID');
check($service->bindChannelWarnings() === [], 'flow: 占位配置正确时没有提醒');
$DB->tables['channel'][3]['config'] = '{"appid":"1001","appkey":"x"}';
check(count($service->bindChannelWarnings()) === 1, 'flow: 通道没写占位时提醒');

check(throws(function () use ($service) { $service->saveSettings(['providers' => 'fuyou', 'bind_channels' => [4]]); }) === '通道 #4 不是 KPay 通道', 'flow: 设置里只能选 KPay 通道');
check(throws(function () use ($service) { $service->saveSettings(['extra_create' => '[1']); }) === '建单附加参数必须是 JSON 对象', 'flow: 附加参数必须是 JSON');
$service->saveSettings(['apikey' => 'ak2', 'apisecret' => '', 'providers' => 'fuyou, tianque', 'bind_channels' => ['3'], 'callback_channel' => '3', 'self_service' => '1', 'user_bind' => '1']);
check($service->setting('apisecret') === 'sk_sp', 'flow: Secret 留空不覆盖');
check($service->setting('providers') === ['fuyou', 'tianque'] && $service->setting('bind_channels') === [3], 'flow: 机构和通道列表解析');
check($service->setting('callback_secret') === 'cbsecret', 'flow: 已有回调密钥不变');

FakeKpayClient::reset();
FakeKpayClient::respond('GET', '/pay/api/service-provider/merchants', ['code' => 0, 'data' => ['list' => [], 'total' => 0]]);
$service->serviceProvider('merchants', ['page' => 2, 'activity' => 'dormant', 'evil' => '1']);
check(FakeKpayClient::last()['uri'] === '/pay/api/service-provider/merchants?page=2&activity=dormant', 'flow: 服务商查询只带白名单参数');
check(throws(function () use ($service) { $service->serviceProvider('../admin', []); }) === '不支持的查询', 'flow: 服务商查询资源白名单');
check(throws(function () use ($service) { $service->submitRateChange(['bindingId' => 1, 'provider' => 'fuyou', 'payMethod' => 'union', 'targetFeeRate' => 0.003, 'reason' => 'x']); }) === '请完整填写费率调整信息', 'flow: 费率调整参数校验');

// 域名验证码写入
$tmpRoot = sys_get_temp_dir() . '/kpay-verify-' . bin2hex(random_bytes(4));
mkdir($tmpRoot);
$lineA = 'kpay-domain-verification=AbCdEfGhIjKlMnOpQrStUvWx';
$lineB = 'kpay-domain-verification=ZyXwVuTsRqPoNmLkJiHg-_12';
check($service->addDomainVerification(' ' . $lineA . ' ', $tmpRoot) === true, 'verify: 写入第一行验证码');
check($service->addDomainVerification($lineB, $tmpRoot) === true, 'verify: 追加第二个商户的验证码');
check($service->addDomainVerification($lineA, $tmpRoot) === false, 'verify: 重复的验证码不再写入');
check(file_get_contents($tmpRoot . '/kpay-domain-verification.txt') === $lineA . "\n" . $lineB . "\n", 'verify: 文件每行一个验证码');
check(throws(function () use ($service, $tmpRoot) { $service->addDomainVerification("kpay-domain-verification=abc\n<?php", $tmpRoot); }) !== false, 'verify: 拒绝格式不对的内容');
check(throws(function () use ($service, $tmpRoot) { $service->addDomainVerification('<script>alert(1)</script>', $tmpRoot); }) !== false, 'verify: 拒绝任意内容');
unlink($tmpRoot . '/kpay-domain-verification.txt');
rmdir($tmpRoot);

// 商户端动作：不同状态下的权限
check(throws(function () use ($service) { kpay_apply_action($service, 'submit', 9, false); }) === '请先填写并保存资料', 'ajax: 没有进件单时不能提交');
check(kpay_apply_action($service, 'detail', 9, false)['data']['apply'] === null, 'ajax: 没有进件单时详情为空');
$DB->tables['config']['kpay_sp']['v'] = json_encode(array_merge(json_decode($DB->tables['config']['kpay_sp']['v'], true), ['self_service' => 0]));
$service = new TestKpayService();
check(throws(function () use ($service) { kpay_apply_action($service, 'save', 9, false); }) === '暂未开放在线申请，请联系平台', 'ajax: 未开放自助时商户不能提交资料');
check(throws(function () use ($service) { kpay_apply_action($service, 'save', 9, true); }) === '请填写商户名称', 'ajax: 管理员不受自助开关限制');
$DB->tables['config']['kpay_sp']['v'] = json_encode(array_merge(json_decode($DB->tables['config']['kpay_sp']['v'], true), ['user_bind' => 0]));
$service = new TestKpayService();
check(throws(function () use ($service) { kpay_apply_action($service, 'bind', 7, false); }) === '请联系平台完成收款账户绑定', 'ajax: 未开放自助绑定时商户不能绑定');
check(throws(function () use ($service) { kpay_apply_action($service, 'nope', 7, false); }) === '不支持的操作', 'ajax: 未知动作');
