<?php
/**
 * 管理后台：KPay 服务商工具（进件、旗下商户、交易与对账、分润、费率调整、投诉）
 */
include("../includes/common.php");
$title='KPay 服务商';
include './head.php';
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
require_once PLUGIN_ROOT.'kpay/inc/KpayAjax.php';
require_once PLUGIN_ROOT.'kpay/inc/views.php';
KpayService::instance()->install();
$csrf = kpay_csrf_token();
$mod = isset($_GET['mod']) ? $_GET['mod'] : '';
?>
<div class="container" style="padding-top:70px;">
<div class="row"><div class="col-md-12">
<?php if($mod === 'apply'){
	$applyUid = intval(isset($_GET['uid']) ? $_GET['uid'] : 0);
	$applyUser = $DB->getRow("SELECT uid,username FROM pre_user WHERE uid=:uid LIMIT 1", [':uid'=>$applyUid]);
	if(!$applyUser) exit('<div class="alert alert-danger">商户不存在</div>');
?>
	<p><a href="./kpay.php#apply" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> 返回</a>
	正在为商户 <b><?php echo htmlspecialchars($applyUser['username'].'（UID '.$applyUser['uid'].'）'); ?></b> 填写进件资料</p>
<?php kpay_render_apply_form(); ?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<?php kpay_render_apply_script('./ajax_kpay.php', $csrf, $applyUid, 'apply_'); ?>
<?php }else{ ?>
<ul class="nav nav-tabs" id="kpay-tabs">
	<li class="active"><a href="#apply" data-toggle="tab">进件单</a></li>
	<li><a href="#merchants" data-toggle="tab">旗下商户</a></li>
	<li><a href="#trades" data-toggle="tab">交易与对账</a></li>
	<li><a href="#commissions" data-toggle="tab">分润流水</a></li>
	<li><a href="#rates" data-toggle="tab">费率调整</a></li>
	<li><a href="#complaints" data-toggle="tab">商户投诉</a></li>
	<li><a href="#settings" data-toggle="tab">设置</a></li>
</ul>
<div class="tab-content" style="padding-top:15px">

	<div class="tab-pane active" id="apply">
		<form class="form-inline" id="apply-search" onsubmit="return false" style="margin-bottom:10px">
			<input class="form-control" name="keyword" placeholder="UID / 商户名称 / 进件单号"/>
			<select class="form-control" name="status">
				<option value="">全部状态</option><option value="draft">草稿</option><option value="submitted">审核中</option>
				<option value="approved">已通过</option><option value="partial_approved">部分通过</option><option value="rejected">已退回</option>
			</select>
			<button class="btn btn-primary" data-load="apply">搜索</button>
			<span class="pull-right form-inline">
				<input class="form-control" id="new-apply-uid" placeholder="商户 UID" style="width:110px"/>
				<button class="btn btn-success" id="new-apply-btn">代商户进件</button>
			</span>
		</form>
		<div class="table-responsive"><table class="table table-striped table-bordered">
			<thead><tr><th>ID</th><th>商户</th><th>商户名称</th><th>进件单号</th><th>状态</th><th>收款账户</th><th>更新时间</th><th>操作</th></tr></thead>
			<tbody id="apply-rows"></tbody>
		</table></div>
		<div id="apply-pager"></div>
	</div>

	<div class="tab-pane" id="merchants">
		<form class="form-inline" onsubmit="return false" style="margin-bottom:10px">
			<select class="form-control" name="activity"><option value="">全部经营状态</option><option value="active">本月有交易</option><option value="idle">本月无交易</option><option value="dormant">静默超过30天</option><option value="never">从未交易</option></select>
			<input class="form-control" name="keyword" placeholder="商户名称 / 商户号 / 手机号"/>
			<button class="btn btn-primary" data-load="merchants">查询</button>
		</form>
		<div class="table-responsive"><table class="table table-striped table-bordered">
			<thead><tr><th>渠道商户ID</th><th>商户</th><th>商户号</th><th>状态</th><th>服务费率</th><th>基础费率</th><th>本月交易额</th><th>本月分润</th><th>累计分润</th><th>最近交易</th><th>代收款授权</th><th>操作</th></tr></thead>
			<tbody id="merchants-rows"></tbody>
		</table></div>
		<div id="merchants-pager"></div>
	</div>

	<div class="tab-pane" id="trades">
		<form class="form-inline" onsubmit="return false" style="margin-bottom:10px">
			<select class="form-control" name="resource">
				<option value="orders">订单</option><option value="refunds">退款</option><option value="settlements">机构结算</option>
			</select>
			<input class="form-control" name="startDate" type="date" title="开始日期"/>
			<input class="form-control" name="endDate" type="date" title="结束日期"/>
			<input class="form-control" name="bindingId" placeholder="渠道商户ID" style="width:110px"/>
			<button class="btn btn-primary" data-load="trades">查询</button>
			<span class="pull-right form-inline">
				<input class="form-control" id="statement-date" type="date" title="对账日期"/>
				<button class="btn btn-default" id="statement-btn"><i class="fa fa-download"></i> 下载对账单</button>
			</span>
		</form>
		<p class="text-muted" id="trades-summary"></p>
		<div class="table-responsive"><table class="table table-striped table-bordered">
			<thead id="trades-head"></thead>
			<tbody id="trades-rows"></tbody>
		</table></div>
		<div id="trades-pager"></div>
	</div>

	<div class="tab-pane" id="commissions">
		<form class="form-inline" onsubmit="return false" style="margin-bottom:10px">
			<select class="form-control" name="type"><option value="">全部</option><option value="commission">分润入账</option><option value="reversal">冲正</option></select>
			<button class="btn btn-primary" data-load="commissions">查询</button>
			<span id="commissions-summary" class="text-muted" style="margin-left:15px"></span>
		</form>
		<div class="table-responsive"><table class="table table-striped table-bordered">
			<thead><tr><th>流水号</th><th>类型</th><th>商户</th><th>订单号</th><th>订单金额</th><th>服务费</th><th>分润</th><th>余额</th><th>时间</th></tr></thead>
			<tbody id="commissions-rows"></tbody>
		</table></div>
		<div id="commissions-pager"></div>
	</div>

	<div class="tab-pane" id="rates">
		<form class="form-inline" onsubmit="return false" style="margin-bottom:10px">
			<select class="form-control" name="status"><option value="">全部状态</option><option value="pending">等待处理</option><option value="approved">处理中</option><option value="applied">已生效</option><option value="rejected">已驳回</option><option value="canceled">已撤回</option><option value="failed">未能生效</option></select>
			<input class="form-control" name="keyword" placeholder="申请单号 / 商户"/>
			<button class="btn btn-primary" data-load="rates">查询</button>
		</form>
		<div class="table-responsive"><table class="table table-striped table-bordered">
			<thead><tr><th>申请单号</th><th>商户</th><th>通道</th><th>支付方式</th><th>当前费率</th><th>目标费率</th><th>状态</th><th>说明</th><th>操作</th></tr></thead>
			<tbody id="rates-rows"></tbody>
		</table></div>
		<div id="rates-pager"></div>
	</div>

	<div class="tab-pane" id="complaints">
		<form class="form-inline" onsubmit="return false" style="margin-bottom:10px">
			<select class="form-control" name="status"><option value="">全部状态</option><option value="pending">待处理</option></select>
			<input class="form-control" name="orderNo" placeholder="订单号"/>
			<button class="btn btn-primary" data-load="complaints">查询</button>
		</form>
		<div class="table-responsive"><table class="table table-striped table-bordered">
			<thead><tr><th>ID</th><th>商户</th><th>订单号</th><th>金额</th><th>支付方式</th><th>类型</th><th>要求退款</th><th>状态</th><th>时间</th></tr></thead>
			<tbody id="complaints-rows"></tbody>
		</table></div>
		<div id="complaints-pager"></div>
	</div>

	<div class="tab-pane" id="settings">
		<form class="form-horizontal" id="settings-form" onsubmit="return false">
			<div class="form-group"><label class="col-sm-2 control-label">接口地址</label><div class="col-sm-10"><input class="form-control" name="apiurl"/></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">API Key</label><div class="col-sm-10"><input class="form-control" name="apikey"/>
				<p class="help-block">用服务商账号在 KPay「API 密钥」页创建「平台 API」密钥，授权域名填本站域名并完成验证。接口权限勾选：创建进件单、查询进件单、上传进件材料、查询旗下商户、查询分润流水、查询费率调整、提交费率调整、查看旗下商户投诉、查询交易与对账；用「服务商代收」再勾选代商户收款、创建订单、查询订单、发起退款；要开事件推送再勾选管理事件推送。</p></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">API Secret</label><div class="col-sm-10"><input class="form-control" type="password" name="apisecret" autocomplete="new-password" placeholder="留空表示不修改"/></div></div>
			<div class="form-group"><label class="col-sm-2 control-label"></label><div class="col-sm-10"><button class="btn btn-default" id="test-btn">测试连接并读取可用机构</button></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">进件机构</label><div class="col-sm-10"><div id="provider-boxes" class="checkbox"></div>
				<input class="form-control" name="providers_text" placeholder="机构代码，逗号分隔，如 fuyou,tianque（点上方测试连接后可勾选）"/></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">套餐</label><div class="col-sm-10"><select class="form-control" name="package_code"><option value="">不指定</option></select></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">回调通道</label><div class="col-sm-10"><select class="form-control" name="callback_channel"><option value="0">不接收回调（只手动刷新）</option></select>
				<p class="help-block">选一个 KPay 通道，用它的地址接收进件状态推送：<code id="callback-url">-</code></p></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">绑定通道</label><div class="col-sm-10"><div id="bind-boxes" class="checkbox"></div>
				<p class="help-block">商户绑定收款账户时，为这些通道生成子通道。通道密钥配置里商户ID填 <code>[appid]</code>、商户密钥填 <code>[appkey]</code>，并在用户组里把对应支付方式设为「用户自定义子通道」。</p></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">收款方式</label><div class="col-sm-10">
				<select class="form-control" name="bind_mode">
					<option value="delegate">服务商代收（推荐）：商户在 KPay 点同意即可，不用建密钥、不用验证域名</option>
					<option value="epay_key">商户自己的 EPay 密钥：商户建 EPay 兼容密钥并验证本站域名</option>
				</select>
				<p class="help-block">两种方式钱都直接结算到商户自己的 KPay 账户。服务商代收用上面这把 API 密钥、以商户身份下单，付款人在 KPay 收银台选择支付方式；商户可以随时在 KPay 撤回授权，撤回后对应子通道自动停用。管理员在进件详情里两种方式都能用，这里决定商户自助绑定时用哪种。</p></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">商户自助</label><div class="col-sm-10">
				<label class="checkbox-inline"><input type="checkbox" name="self_service" value="1"/> 允许商户在商户中心自己填写进件</label>
				<label class="checkbox-inline"><input type="checkbox" name="user_bind" value="1"/> 允许商户审核通过后自己绑定收款账户</label>
				<p class="help-block">商户中心入口：<code>/user/kpay.php</code></p></div></div>
			<div class="form-group"><label class="col-sm-2 control-label">建单附加参数</label><div class="col-sm-10"><textarea class="form-control" name="extra_create" rows="3" placeholder='选填，JSON。如 {"fuyouConfigId":12,"fuyouWechatFeeRate":0.0038}'></textarea></div></div>
			<div class="form-group"><div class="col-sm-offset-2 col-sm-10"><button class="btn btn-primary" id="save-btn">保存设置</button></div></div>
			<div id="settings-warnings"></div>
		</form>
		<hr/>
		<div class="form-horizontal">
			<div class="form-group"><label class="col-sm-2 control-label">事件推送</label><div class="col-sm-10">
				<p class="form-control-static" id="webhook-state">-</p>
				<p class="help-block">开启后，KPay 把进件状态和商户授权的变化实时推到 <code id="webhook-url">-</code>，商户同意授权后代收子通道马上启用，不用等页面刷新。KPay 一个服务商只能设一个推送地址，已经推到你自己的系统时不要开。</p>
				<button class="btn btn-default" id="webhook-enable">开启</button>
				<button class="btn btn-default" id="webhook-test">发送测试</button>
				<button class="btn btn-default" id="webhook-refresh">刷新状态</button>
				<button class="btn btn-link text-danger" id="webhook-disable">关闭</button>
			</div></div>
		</div>
	</div>
</div>

<div class="modal" id="detail-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
	<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">进件详情</h4></div>
	<div class="modal-body" id="detail-body"></div>
	<div class="modal-footer">
		<div class="text-left" style="margin-bottom:8px">
			<label class="radio-inline"><input type="radio" name="bind-mode" value="delegate" checked/> 服务商代收</label>
			<label class="radio-inline"><input type="radio" name="bind-mode" value="epay_key"/> 商户 EPay 密钥</label>
		</div>
		<div data-bind-mode="delegate" class="form-inline text-left" style="margin-bottom:8px">
			<input class="form-control" id="bind-binding" placeholder="渠道商户ID（留空自动查找）" style="width:220px"/>
			<button class="btn btn-success" id="bind-delegate-btn">绑定并申请授权</button>
		</div>
		<div data-bind-mode="epay_key" style="display:none">
			<div class="input-group" style="margin-bottom:8px">
				<input class="form-control" id="verify-line" placeholder="商户的域名验证码 kpay-domain-verification=..."/>
				<span class="input-group-btn"><button class="btn btn-default" id="verify-btn">写入验证文件</button></span>
			</div>
			<div class="form-inline text-left" style="margin-bottom:8px">
				<input class="form-control" id="bind-pid" placeholder="商户ID" style="width:120px"/>
				<input class="form-control" id="bind-key" type="password" placeholder="EPay 密钥" autocomplete="new-password" style="width:200px"/>
				<button class="btn btn-success" id="bind-btn">绑定收款账户</button>
			</div>
		</div>
		<button class="btn btn-default" id="detail-sync">同步机构状态</button>
		<button class="btn btn-primary" id="detail-submit">提交审核</button>
	</div>
</div></div></div>

<div class="modal" id="rate-modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
	<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">申请调整费率</h4></div>
	<div class="modal-body"><form class="form-horizontal" id="rate-form" onsubmit="return false">
		<input type="hidden" name="bindingId"/>
		<div class="form-group"><label class="col-sm-3 control-label">商户</label><div class="col-sm-9"><p class="form-control-static" id="rate-merchant"></p></div></div>
		<div class="form-group"><label class="col-sm-3 control-label">通道代码</label><div class="col-sm-9"><input class="form-control" name="provider" placeholder="如 fuyou、huifu、tianque"/></div></div>
		<div class="form-group"><label class="col-sm-3 control-label">支付方式</label><div class="col-sm-9"><select class="form-control" name="payMethod"><option value="wechat">微信</option><option value="alipay">支付宝</option></select></div></div>
		<div class="form-group"><label class="col-sm-3 control-label">目标费率</label><div class="col-sm-9"><input class="form-control" name="targetFeeRate" placeholder="小数，0.0035 即 0.35%"/></div></div>
		<div class="form-group"><label class="col-sm-3 control-label">调整理由</label><div class="col-sm-9"><textarea class="form-control" name="reason" maxlength="200"></textarea></div></div>
	</form></div>
	<div class="modal-footer"><button class="btn btn-primary" id="rate-submit">提交申请</button></div>
</div></div></div>

<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script>
(function ($) {
	var csrf = <?php echo json_encode($csrf); ?>;
	var pages = { apply: 1, merchants: 1, trades: 1, commissions: 1, rates: 1, complaints: 1 };
	var authLabels = { pending: '等待商户同意', active: '已授权', rejected: '商户已拒绝', revoked: '已撤回', canceled: '申请已撤回' };
	var orderStatus = { paid: '已支付', pending: '待支付', refunded: '已退款', partial_refunded: '部分退款', closed: '已关闭', expired: '已过期', failed: '失败' };
	var refundStatus = { pending: '待商户确认', processing: '处理中', succeeded: '已到账', closed: '已关闭' };
	var payMethods = { wechat: '微信', alipay: '支付宝', unionpay: '云闪付' };
	var currentApply = null;

	function esc(v) { return $('<div/>').text(v == null ? '' : String(v)).html(); }
	function pct(v) { return v == null || v === '' ? '-' : (Number(v) * 100).toFixed(2) + '%'; }
	function money(v) { return v == null || v === '' ? '-' : Number(v).toFixed(2); }
	function time(v) { return v ? String(v).replace('T', ' ').substr(0, 19) : '-'; }
	function toast(msg, ok) { if (msg) layer.msg(msg, { icon: ok ? 1 : 2, time: ok ? 2000 : 4000 }); }
	function post(act, data, done) {
		var ii = layer.load(2, { shade: [0.1, '#fff'] });
		// 多选框要保留同名字段，所以数组形式（serializeArray）的数据原样追加 csrf
		var payload = $.isArray(data) ? data.concat([{ name: 'csrf', value: csrf }]) : $.extend({ csrf: csrf }, data || {});
		$.ajax({ url: './ajax_kpay.php?act=' + act, type: 'POST', dataType: 'json', data: payload,
			success: function (res) { layer.close(ii); if (res.code !== 0) { toast(res.msg || '操作失败'); return; } toast(res.msg, true); done && done(res.data); },
			error: function () { layer.close(ii); toast('网络错误'); } });
	}
	function formValues($form) {
		var data = {};
		$.each($form.serializeArray(), function (_, item) { data[item.name] = item.value; });
		return data;
	}
	function pager(name, page, pageSize, total) {
		var pagesCount = Math.max(1, Math.ceil((total || 0) / (pageSize || 20)));
		$('#' + name + '-pager').html('<p class="text-muted">共 ' + (total || 0) + ' 条，第 ' + page + '/' + pagesCount + ' 页 '
			+ (page > 1 ? '<a href="javascript:;" data-page="' + name + '" data-to="' + (page - 1) + '">上一页</a> ' : '')
			+ (page < pagesCount ? '<a href="javascript:;" data-page="' + name + '" data-to="' + (page + 1) + '">下一页</a>' : '') + '</p>');
	}
	function empty(cols) { return '<tr><td colspan="' + cols + '" class="text-center text-muted">暂无数据</td></tr>'; }

	var loaders = {
		apply: function () {
			post('list', $.extend(formValues($('#apply-search')), { page: pages.apply }), function (data) {
				var html = '';
				$.each(data.list, function (_, r) {
					html += '<tr><td>' + r.id + '</td><td>' + esc(r.username || '') + '<br><small class="text-muted">UID ' + r.uid + '</small></td><td>' + esc(r.merchant_name || '-')
						+ '</td><td>' + esc(r.application_no || '未创建') + '</td><td>' + esc(r.status_label || r.status) + (r.merchant_code ? '<br><small class="text-muted">' + esc(r.merchant_code) + '</small>' : '')
						+ '</td><td>' + (r.sp_binding_id ? '代收 #' + esc(r.sp_binding_id) + '<br><small class="text-muted">' + esc(authLabels[r.auth_status] || r.auth_status || '') + '</small>' : (r.bind_pid ? esc(r.bind_pid) : '<span class="text-muted">未绑定</span>')) + '</td><td>' + esc(r.updatetime || '')
						+ '</td><td><a href="./kpay.php?mod=apply&uid=' + r.uid + '" class="btn btn-xs btn-default">资料</a> '
						+ (r.application_no ? '<button class="btn btn-xs btn-info" data-detail="' + r.id + '">详情</button>' : '') + '</td></tr>';
				});
				$('#apply-rows').html(html || empty(8));
				pager('apply', data.page, data.pageSize, data.total);
			});
		},
		merchants: function () {
			post('sp', $.extend(formValues($('#merchants form')), { resource: 'merchants', page: pages.merchants, pageSize: 20 }), function (data) {
				var html = '';
				$.each(data.list || [], function (_, r) {
					var auth = r.authorization || null, hasPayment = auth && (auth.scopes || []).indexOf('payment') >= 0;
					var authText = auth && (hasPayment || auth.status !== 'active') ? (authLabels[auth.status] || auth.status) : '未申请';
					var authBtn = auth && (auth.status === 'pending' || auth.status === 'active')
						? '<button class="btn btn-xs btn-link" data-auth-cancel="' + r.id + '">' + (auth.status === 'pending' ? '撤回申请' : '放弃授权') + '</button>'
						: (r.merchantId && r.status === 'active' ? '<button class="btn btn-xs btn-link" data-auth-request="' + r.id + '">申请授权</button>' : '');
					html += '<tr><td>' + r.id + '</td><td>' + esc(r.merchantName || r.username || '-') + '</td><td>' + esc(r.merchantCode || '-') + '</td><td>' + esc(r.status) + ' / ' + esc(r.activityStatus || '-')
						+ '</td><td>' + pct(r.effectiveServiceFeeRate) + '</td><td>' + pct(r.platformBaseFeeRate) + '</td><td>' + money(r.monthVolume) + '</td><td>' + money(r.monthCommission)
						+ '</td><td>' + money(r.totalCommission) + '</td><td>' + time(r.lastPaidAt) + '</td><td>' + esc(authText) + authBtn
						+ '</td><td><button class="btn btn-xs btn-default" data-rate="' + r.id + '" data-name="' + esc(r.merchantName || r.username || '') + '">调费率</button></td></tr>';
				});
				$('#merchants-rows').html(html || empty(12));
				pager('merchants', data.page || 1, data.pageSize || 20, data.total);
			});
		},
		trades: function () {
			var q = formValues($('#trades form')), resource = q.resource;
			var params = { resource: resource, page: pages.trades, pageSize: 50, bindingId: q.bindingId };
			if (resource === 'settlements') {
				params.startDate = q.startDate; params.endDate = q.endDate;
			} else {
				params.startTime = q.startDate;
				// 结束日期要包含当天，接口是「不含结束」
				if (q.endDate) { var d = new Date(q.endDate + 'T00:00:00'); d.setDate(d.getDate() + 1); params.endTime = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
			}
			post('sp', params, function (data) {
				var head = '', html = '', cols = 0;
				if (resource === 'orders') {
					head = '<tr><th>平台订单号</th><th>商户订单号</th><th>商户</th><th>商品</th><th>金额</th><th>已退款</th><th>服务费</th><th>支付方式</th><th>状态</th><th>下单时间</th><th>支付时间</th></tr>'; cols = 11;
					$.each(data.list || [], function (_, r) {
						html += '<tr><td>' + esc(r.orderNo) + (r.delegated ? ' <span class="label label-info">代收</span>' : '') + '</td><td>' + esc(r.merchantOrderNo || '-') + '</td><td>' + esc(r.merchantName || '-') + '<br><small class="text-muted">' + esc(r.bindingId || '') + '</small></td><td>' + esc(r.productName || '')
							+ '</td><td>' + money(r.amount) + '</td><td>' + money(r.refundedAmount) + '</td><td>' + money(r.serviceFee) + '</td><td>' + esc(payMethods[r.payMethod] || r.payMethod || '-') + '</td><td>' + esc(orderStatus[r.status] || r.status)
							+ '</td><td>' + time(r.createdAt) + '</td><td>' + time(r.paidAt) + '</td></tr>';
					});
				} else if (resource === 'refunds') {
					head = '<tr><th>退款单号</th><th>原订单号</th><th>商户订单号</th><th>商户</th><th>退款金额</th><th>原因</th><th>状态</th><th>申请时间</th><th>到账时间</th></tr>'; cols = 9;
					$.each(data.list || [], function (_, r) {
						html += '<tr><td>' + esc(r.refundNo) + '</td><td>' + esc(r.orderNo) + '</td><td>' + esc(r.merchantOrderNo || '-') + '</td><td>' + esc(r.merchantName || '-') + '</td><td>' + money(r.refundAmount)
							+ '</td><td>' + esc(r.reason || '') + '</td><td>' + esc(refundStatus[r.status] || r.status) + '</td><td>' + time(r.createdAt) + '</td><td>' + time(r.refundedAt) + '</td></tr>';
					});
				} else {
					head = '<tr><th>结算日</th><th>商户</th><th>机构</th><th>机构商户号</th><th>到账金额</th><th>手续费</th><th>状态</th><th>到账卡</th><th>进件单号</th></tr>'; cols = 9;
					$.each(data.list || [], function (_, r) {
						html += '<tr><td>' + esc(r.settleDate) + '</td><td>' + esc(r.merchantName || '-') + '</td><td>' + esc(r.providerLabel || r.provider) + '</td><td>' + esc(r.institutionMerchantNo || '-') + '</td><td>' + money(r.amount)
							+ '</td><td>' + money(r.fee) + '</td><td>' + esc(r.statusText || r.status) + '</td><td>' + (r.accountTail ? '尾号 ' + esc(r.accountTail) : '-') + '</td><td>' + esc(r.applicationNo || '-') + '</td></tr>';
					});
				}
				$('#trades-head').html(head);
				$('#trades-rows').html(html || empty(cols));
				var s = data.summary;
				$('#trades-summary').text(resource === 'settlements' && s ? (data.startDate + ' 至 ' + data.endDate + '：' + s.count + ' 笔，到账 ' + money(s.amount) + (s.failed ? '，失败 ' + s.failed + ' 笔' : '')) : '');
				pager('trades', data.page || 1, data.pageSize || 50, data.total);
			});
		},
		commissions: function () {
			post('sp', $.extend(formValues($('#commissions form')), { resource: 'commissions', page: pages.commissions, pageSize: 50 }), function (data) {
				var html = '';
				$.each(data.list || [], function (_, r) {
					html += '<tr><td>' + esc(r.billNo) + '</td><td>' + (r.type === 'reversal' ? '冲正' : '分润') + '</td><td>' + esc(r.merchantName || '-') + '</td><td>' + esc(r.orderNo || '-')
						+ '</td><td>' + money(r.orderAmount) + '</td><td>' + money(r.serviceFeeAmount) + '</td><td>' + money(r.amount) + '</td><td>' + money(r.balanceAfter) + '</td><td>' + time(r.createdAt) + '</td></tr>';
				});
				$('#commissions-rows').html(html || empty(9));
				var s = data.summary || {};
				$('#commissions-summary').text('累计分润 ' + money(s.totalCommission) + '，本月 ' + money(s.thisMonthCommission));
				pager('commissions', data.page || 1, data.pageSize || 50, data.total);
			});
		},
		rates: function () {
			post('sp', $.extend(formValues($('#rates form')), { resource: 'rate-changes', page: pages.rates, pageSize: 20 }), function (data) {
				var html = '';
				$.each(data.list || [], function (_, r) {
					html += '<tr><td>' + esc(r.requestNo) + '</td><td>' + esc(r.merchantName || '-') + '</td><td>' + esc(r.provider) + '</td><td>' + (r.payMethod === 'alipay' ? '支付宝' : '微信')
						+ '</td><td>' + pct(r.currentFeeRate) + '</td><td>' + pct(r.targetFeeRate) + '</td><td>' + esc(r.status) + '</td><td>' + esc(r.reviewRemark || r.failureReason || r.reason || '')
						+ '</td><td>' + (r.status === 'pending' ? '<button class="btn btn-xs btn-warning" data-cancel="' + r.id + '">撤回</button>' : '') + '</td></tr>';
				});
				$('#rates-rows').html(html || empty(9));
				pager('rates', data.page || 1, data.pageSize || 20, data.total);
			});
		},
		complaints: function () {
			post('sp', $.extend(formValues($('#complaints form')), { resource: 'complaints', page: pages.complaints, pageSize: 20 }), function (data) {
				var html = '';
				$.each(data.list || [], function (_, r) {
					html += '<tr><td>' + r.id + '</td><td>' + esc(r.merchantName || '-') + '</td><td>' + esc(r.orderNo || '') + '<br><small class="text-muted">' + esc(r.merchantOrderNo || '') + '</small></td><td>' + money(r.amount)
						+ '</td><td>' + esc(r.payMethod) + '</td><td>' + esc(r.reasonType) + '</td><td>' + (r.refundRequested ? '是' : '否') + '</td><td>' + esc(r.status) + '</td><td>' + time(r.createdAt) + '</td></tr>';
				});
				$('#complaints-rows').html(html || empty(9));
				pager('complaints', data.page || 1, data.pageSize || 20, data.total);
			});
		}
	};

	function renderProviderBoxes(providers, selected) {
		var html = '';
		$.each(providers, function (_, p) {
			html += '<label class="checkbox-inline"><input type="checkbox" name="providers[]" value="' + esc(p.provider) + '"' + (selected.indexOf(p.provider) >= 0 ? ' checked' : '') + (p.ready ? '' : ' disabled') + '/> '
				+ esc(p.label || p.provider) + (p.ready ? '' : '（未就绪）') + '</label>';
		});
		$('#provider-boxes').html(html);
		$('[name=providers_text]').toggle(!providers.length);
	}
	function loadSettings() {
		post('settings', {}, function (data) {
			var s = data.settings, $f = $('#settings-form');
			$f.find('[name=apiurl]').val(s.apiurl);
			$f.find('[name=apikey]').val(s.apikey);
			$f.find('[name=apisecret]').attr('placeholder', s.hasSecret ? '已设置，留空表示不修改' : '请输入');
			$f.find('[name=providers_text]').val(s.providers.join(','));
			$f.find('[name=extra_create]').val(s.extra_create);
			$f.find('[name=self_service]').prop('checked', !!s.self_service);
			$f.find('[name=user_bind]').prop('checked', !!s.user_bind);
			$f.find('[name=bind_mode]').val(s.bind_mode);
			$('#webhook-url').text(data.webhookUrl || '先选择回调通道并保存');
			$('#webhook-state').text(data.webhookOn ? '已开启' : '未开启');
			$('#webhook-enable').toggle(!data.webhookOn);
			$('#webhook-test,#webhook-refresh,#webhook-disable').toggle(!!data.webhookOn);
			var channelOptions = '<option value="0">不接收回调（只手动刷新）</option>', bindHtml = '';
			$.each(data.channels, function (_, c) {
				channelOptions += '<option value="' + c.id + '">#' + c.id + ' ' + esc(c.name) + '</option>';
				bindHtml += '<label class="checkbox-inline"><input type="checkbox" name="bind_channels[]" value="' + c.id + '"' + (s.bind_channels.indexOf(Number(c.id)) >= 0 ? ' checked' : '') + '/> #' + c.id + ' ' + esc(c.name) + '</label>';
			});
			$f.find('[name=callback_channel]').html(channelOptions).val(String(s.callback_channel));
			$('#bind-boxes').html(bindHtml || '<span class="text-muted">还没有使用 KPay 插件的支付通道</span>');
			$('#callback-url').text(data.callbackUrl || '-');
			$f.find('[name=package_code]').html('<option value="">不指定</option>' + (s.package_code ? '<option value="' + esc(s.package_code) + '">' + esc(s.package_code) + '</option>' : '')).val(s.package_code);
			$f.data('selected-providers', s.providers);
			$('#settings-warnings').html($.map(data.warnings, function (w) { return '<div class="alert alert-warning">' + esc(w) + '</div>'; }).join(''));
		});
	}

	$('#test-btn').on('click', function () {
		var $f = $('#settings-form');
		post('options', {}, function (data) {
			renderProviderBoxes(data.providers, $f.data('selected-providers') || []);
			var current = $f.find('[name=package_code]').val(), options = '<option value="">不指定</option>';
			$.each(data.packages, function (_, p) { options += '<option value="' + esc(p.code) + '">' + esc(p.label || p.code) + '</option>'; });
			$f.find('[name=package_code]').html(options).val(current);
		});
	});
	$('#save-btn').on('click', function () {
		var $f = $('#settings-form'), data = $f.serializeArray();
		if (!$('#provider-boxes input').length) data.push({ name: 'providers', value: $f.find('[name=providers_text]').val() });
		post('settings_save', data, function () { loadSettings(); });
	});

	function enableWebhook(force) {
		post('webhook_enable', { force: force ? 1 : 0 }, function (data) {
			if (!data.configured) {
				if (confirm('KPay 的事件推送现在发往 ' + data.currentUrl + '，改成本站后那边就收不到了。确认改成本站？')) enableWebhook(true);
				return;
			}
			loadSettings();
		});
	}
	$('#webhook-enable').on('click', function () { enableWebhook(false); });
	$('#webhook-test').on('click', function () { post('webhook_test', {}); });
	$('#webhook-disable').on('click', function () { if (confirm('确认关闭事件推送？')) post('webhook_disable', {}, loadSettings); });
	$('#webhook-refresh').on('click', function () {
		post('webhook_status', {}, function (w) {
			var text = w.enabled ? '已开启' : (w.url ? '推送地址已改为 ' + w.url + '，本站不再接收' : '未开启');
			if (w.enabled && w.status === 'disabled') text += '（KPay 侧已暂停）';
			if (w.lastDeliveredAt) text += '，最近送达 ' + time(w.lastDeliveredAt);
			if (w.lastFailedAt) text += '，最近失败 ' + time(w.lastFailedAt) + (w.lastError ? '：' + w.lastError : '');
			$('#webhook-state').text(text);
		});
	});

	$('#statement-btn').on('click', function () {
		var date = $('#statement-date').val();
		if (!date) { toast('请选择对账日期'); return; }
		window.open('./ajax_kpay.php?act=statement&date=' + encodeURIComponent(date) + '&csrf=' + encodeURIComponent(csrf));
	});
	$(document).on('click', '[data-auth-request]', function () {
		if (!confirm('向这个商户申请「代为收款」授权？商户在 KPay 同意后生效。')) return;
		post('auth_request', { bindingId: $(this).data('auth-request') }, function () { loaders.merchants(); });
	});
	$(document).on('click', '[data-auth-cancel]', function () {
		if (!confirm('确认撤回？已绑定这个商户的代收子通道会立即停用。')) return;
		post('auth_cancel', { bindingId: $(this).data('auth-cancel') }, function () { loaders.merchants(); });
	});

	$(document).on('click', '[data-load]', function () { var name = $(this).data('load'); pages[name] = 1; loaders[name](); });
	$(document).on('click', '[data-page]', function () { var name = $(this).data('page'); pages[name] = Number($(this).data('to')); loaders[name](); });
	$('#kpay-tabs a').on('shown.bs.tab', function (e) {
		var name = $(e.target).attr('href').substr(1);
		if (name === 'settings') loadSettings(); else if (loaders[name] && !$('#' + name + '-rows').children().length) loaders[name]();
	});
	$('#new-apply-btn').on('click', function () {
		var uid = parseInt($('#new-apply-uid').val(), 10);
		if (uid > 0) location.href = './kpay.php?mod=apply&uid=' + uid; else toast('请输入商户 UID');
	});

	function showDetail(id, sync) {
		post('apply_detail_admin', { id: id, sync: sync ? 1 : 0 }, function (data) {
			currentApply = data.apply;
			var m = data.merchant, a = data.apply, html = '';
			html += '<p>进件单号：<code>' + esc(a.application_no) + '</code>　状态：<b>' + esc(m.statusLabel) + '</b>' + (a.merchant_code ? '　平台商户号：<code>' + esc(a.merchant_code) + '</code>' : '') + '</p>';
			if (m.reviewRemark) html += '<div class="alert alert-danger">' + esc(m.reviewRemark) + '</div>';
			if (m.missingFields.length || m.missingMaterials.length) {
				html += '<p class="text-muted">缺少：' + esc(m.missingFields.concat($.map(m.missingMaterials, function (x) { return x.label; })).join('、')) + '</p>';
			}
			html += '<table class="table table-condensed table-bordered"><thead><tr><th>机构</th><th>状态</th><th>机构商户号</th><th>说明</th></tr></thead><tbody>';
			$.each(data.providers, function (_, p) {
				html += '<tr><td>' + esc(p.label) + '</td><td>' + esc(p.status) + '</td><td>' + esc(p.merchantCode || '-') + '</td><td>' + esc(p.remark || '') + (p.signUrl ? ' <a href="' + esc(p.signUrl) + '" target="_blank" rel="noopener">确认链接</a>' : '') + '</td></tr>';
			});
			html += '</tbody></table>';
			if (a.sp_binding_id) {
				html += '<div class="alert ' + (a.auth_status === 'active' ? 'alert-success' : 'alert-warning') + '">服务商代收：渠道商户ID <b>' + esc(a.sp_binding_id) + '</b>，授权状态 <b>' + esc(a.auth_label) + '</b>'
					+ (a.auth_status === 'active' ? '，子通道已启用。' : (a.auth_status === 'pending' ? '。请商户登录 KPay，在「账户中心 · 服务商授权」里同意，同意后子通道自动启用。' : '，子通道已停用，可以重新绑定再次申请。')) + '</div>';
			} else if (a.bind_pid) {
				html += '<div class="alert alert-success">已用商户 EPay 密钥绑定：商户ID ' + esc(a.bind_pid) + '</div>';
			}
			html += '<p class="text-muted">审核通过后，先在 KPay「进件与代理 → 聚合进件工作台」打开这张进件单，生成完成开通链接（目标用户ID填商户的 KPay 用户ID）发给商户，商户开通后再绑定：</p><ul class="text-muted">'
				+ '<li><b>服务商代收</b>：点「绑定并申请授权」，KPay 通知商户同意，同意后就能收款。渠道商户ID留空时按平台商户号自动查找，查不到再到「旗下商户」里找。</li>'
				+ '<li><b>商户 EPay 密钥</b>：商户创建 EPay 兼容密钥，授权域名填本站域名，把 KPay 给的验证码写入下方并完成验证，再填入商户ID和 EPay Key。</li></ul>';
			$('#detail-body').html(html);
			$('#detail-submit').toggle(!!m.canSubmit);
			$('[name=bind-mode][value=' + (a.bind_pid && !a.sp_binding_id ? 'epay_key' : data.bindMode) + ']').prop('checked', true).trigger('change');
			$('#bind-binding').val(a.sp_binding_id || '');
			$('#detail-modal').modal('show');
		});
	}
	$('[name=bind-mode]').on('change', function () {
		var mode = $('[name=bind-mode]:checked').val();
		$('[data-bind-mode]').each(function () { $(this).toggle($(this).data('bind-mode') === mode); });
	});
	$('#bind-delegate-btn').on('click', function () {
		if (!currentApply) return;
		post('apply_bind', { uid: currentApply.uid, mode: 'delegate', bindingId: $('#bind-binding').val() }, function () { showDetail(currentApply.id, false); loaders.apply(); });
	});
	$(document).on('click', '[data-detail]', function () { showDetail($(this).data('detail'), false); });
	$('#detail-sync').on('click', function () { if (currentApply) showDetail(currentApply.id, true); });
	$('#detail-submit').on('click', function () {
		if (!currentApply || !confirm('确认提交审核？')) return;
		post('apply_submit', { uid: currentApply.uid }, function () { showDetail(currentApply.id, false); loaders.apply(); });
	});
	$('#verify-btn').on('click', function () {
		if (!currentApply) return;
		post('apply_verify_domain', { uid: currentApply.uid, line: $('#verify-line').val() }, function () { $('#verify-line').val(''); });
	});
	$('#bind-btn').on('click', function () {
		if (!currentApply) return;
		post('apply_bind', { uid: currentApply.uid, mode: 'epay_key', pid: $('#bind-pid').val(), key: $('#bind-key').val() }, function () { $('#bind-key').val(''); showDetail(currentApply.id, false); loaders.apply(); });
	});

	$(document).on('click', '[data-rate]', function () {
		$('#rate-form')[0].reset();
		$('#rate-form [name=bindingId]').val($(this).data('rate'));
		$('#rate-merchant').text($(this).data('name'));
		$('#rate-modal').modal('show');
	});
	$('#rate-submit').on('click', function () {
		post('rate_submit', formValues($('#rate-form')), function () { $('#rate-modal').modal('hide'); });
	});
	$(document).on('click', '[data-cancel]', function () {
		if (!confirm('确认撤回这笔申请？')) return;
		post('rate_cancel', { id: $(this).data('cancel') }, function () { loaders.rates(); });
	});

	(function () {
		var d = new Date(); d.setDate(d.getDate() - 1);
		$('#statement-date').val(d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2));
	})();
	loaders.apply();
})(jQuery);
</script>
<?php } ?>
</div></div>
</div>
