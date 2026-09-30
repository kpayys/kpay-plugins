<?php

/**
 * 进件表单：kpay_render_apply_form() 输出 HTML，kpay_render_apply_script() 输出脚本
 * （商户中心的 jQuery 在页脚加载，所以脚本要放在页脚之后输出）
 */
function kpay_render_apply_form()
{
	?>
<div id="kpay-apply">
	<div class="alert alert-warning" id="kpay-notice" style="display:none"></div>

	<div class="panel panel-default" id="kpay-status-panel" style="display:none">
		<div class="panel-heading font-bold">申请进度</div>
		<div class="panel-body">
			<p>当前状态：<b id="kpay-status-label">-</b> <span class="text-muted" id="kpay-progress"></span></p>
			<div id="kpay-remark" class="alert alert-danger" style="display:none"></div>
			<div id="kpay-missing" style="display:none"><p class="text-muted">还需要补充：</p><ul id="kpay-missing-list"></ul></div>
			<div id="kpay-confirm" style="display:none"><p>请打开以下链接完成确认：</p><ul id="kpay-confirm-list"></ul></div>
			<div id="kpay-bound" class="alert alert-success" style="display:none"></div>
			<button type="button" class="btn btn-default btn-sm" data-kpay-act="sync"><i class="fa fa-refresh"></i> 刷新状态</button>
			<button type="button" class="btn btn-primary btn-sm" data-kpay-act="submit" id="kpay-submit-btn" style="display:none">提交审核</button>
			<button type="button" class="btn btn-default btn-sm" id="kpay-new-btn" style="display:none">重新申请</button>
		</div>
	</div>

	<div class="panel panel-default" id="kpay-bind-panel" style="display:none">
		<div class="panel-heading font-bold">绑定收款账户</div>
		<div class="panel-body">
			<p class="text-muted">审核通过后，登录 KPay 商户后台「EPay 配置」页面，把商户ID和密钥填到这里即可开始收款。</p>
			<div class="form-inline">
				<input type="text" class="form-control" id="kpay-bind-pid" placeholder="商户ID"/>
				<input type="password" class="form-control" id="kpay-bind-key" placeholder="密钥" autocomplete="new-password"/>
				<button type="button" class="btn btn-success" data-kpay-act="bind">绑定</button>
			</div>
		</div>
	</div>

	<form id="kpay-form" class="form-horizontal" onsubmit="return false">
		<div class="panel panel-default">
			<div class="panel-heading font-bold">基本信息</div>
			<div class="panel-body">
				<div class="form-group"><label class="col-sm-2 control-label">主体类型</label><div class="col-sm-10">
					<select class="form-control" name="subjectType">
						<option value="personal">个人（无营业执照）</option>
						<option value="individual_business">个体工商户</option>
						<option value="enterprise">企业</option>
					</select></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">商户名称</label><div class="col-sm-10"><input class="form-control" name="merchantName" maxlength="50" placeholder="店铺或公司简称"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">联系人手机</label><div class="col-sm-10"><input class="form-control" name="contactMobile" maxlength="11"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">联系邮箱</label><div class="col-sm-10"><input class="form-control" name="contactEmail" maxlength="100"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">经营类目</label><div class="col-sm-10"><input class="form-control" name="industryKeyword" maxlength="30" placeholder="如：便利店、网店、软件服务"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">行业编码</label><div class="col-sm-10"><input class="form-control" name="industryCodePreferred" maxlength="10" placeholder="选填，知道 MCC 编码时填写"/></div></div>
				<div class="form-group" data-kpay-alipay style="display:none"><label class="col-sm-2 control-label">支付宝账号</label><div class="col-sm-10"><input class="form-control" name="alipayLogonId" maxlength="100" placeholder="本人已实名的支付宝手机号或邮箱"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">经营地区</label><div class="col-sm-10">
					<div class="row">
						<div class="col-xs-4"><select class="form-control" name="provinceCode"><option value="">省</option></select></div>
						<div class="col-xs-4"><select class="form-control" name="cityCode"><option value="">市</option></select></div>
						<div class="col-xs-4"><select class="form-control" name="countyCode"><option value="">区县</option></select></div>
					</div></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">详细地址</label><div class="col-sm-10"><input class="form-control" name="addressDetail" maxlength="100"/></div></div>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading font-bold">经营者 / 法人</div>
			<div class="panel-body">
				<p class="text-muted">上传身份证后会自动识别，识别结果可以修改。</p>
				<div class="form-group"><label class="col-sm-2 control-label">姓名</label><div class="col-sm-10"><input class="form-control" name="legalName" maxlength="30"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">身份证号</label><div class="col-sm-10"><input class="form-control" name="legalIdNumber" maxlength="18"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">证件有效期</label><div class="col-sm-10">
					<div class="row">
						<div class="col-xs-6"><input class="form-control" name="legalIdValidFrom" placeholder="开始，如 2020-01-01"/></div>
						<div class="col-xs-6"><input class="form-control" name="legalIdValidUntil" placeholder="结束，长期填 长期"/></div>
					</div></div></div>
				<div data-kpay-license>
					<div class="form-group"><label class="col-sm-2 control-label">营业执照号</label><div class="col-sm-10"><input class="form-control" name="businessLicenseNumber" maxlength="30"/></div></div>
					<div class="form-group"><label class="col-sm-2 control-label">执照名称</label><div class="col-sm-10"><input class="form-control" name="companyName" maxlength="60"/></div></div>
				</div>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading font-bold">结算账户</div>
			<div class="panel-body">
				<div class="form-group"><label class="col-sm-2 control-label">户名</label><div class="col-sm-10"><input class="form-control" name="bankAccountName" maxlength="60"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">卡号</label><div class="col-sm-10">
					<div class="input-group"><input class="form-control" name="bankAccountNo" maxlength="30"/>
					<span class="input-group-btn"><button type="button" class="btn btn-default" data-kpay-act="bank">识别开户行</button></span></div></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">开户银行</label><div class="col-sm-10"><input class="form-control" name="bankName" maxlength="60"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">开户支行</label><div class="col-sm-10"><input class="form-control" name="bankBranchName" maxlength="80"/></div></div>
				<div class="form-group"><label class="col-sm-2 control-label">联行号</label><div class="col-sm-10"><input class="form-control" name="settlementInterBankNo" maxlength="20"/></div></div>
				<input type="hidden" name="bankProvince"/><input type="hidden" name="bankCity"/>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading font-bold">资料照片</div>
			<div class="panel-body">
				<p class="text-muted">先保存资料再上传照片。支持 jpg、png、gif、webp，单张不超过 5MB。</p>
				<table class="table table-condensed"><tbody id="kpay-materials"></tbody></table>
			</div>
		</div>

		<div class="text-center" style="margin-bottom:30px">
			<button type="button" class="btn btn-primary" data-kpay-act="save">保存资料</button>
		</div>
	</form>
	<input type="file" id="kpay-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none"/>
</div>
<?php
}

function kpay_render_apply_script($ajaxUrl, $csrf, $uid, $actPrefix = '')
{
	?>
<script>
(function ($) {
	var ajaxUrl = <?php echo json_encode($ajaxUrl); ?>;
	var csrf = <?php echo json_encode($csrf); ?>;
	var uid = <?php echo (int)$uid; ?>;
	var actPrefix = <?php echo json_encode($actPrefix); ?>;
	var state = { data: null, pendingMaterial: '', startNew: false };
	var $root = $('#kpay-apply');
	var $form = $('#kpay-form');

	function esc(value) {
		return $('<div/>').text(value == null ? '' : String(value)).html();
	}
	function toast(msg, ok) {
		if (!msg) return;
		if (window.layer) { layer.msg(msg, { icon: ok ? 1 : 2 }); } else { alert(msg); }
	}
	function request(act, payload, done, isUpload) {
		var loading = window.layer ? layer.load(2, { shade: [0.1, '#fff'] }) : null;
		var options = { url: ajaxUrl + '?act=' + actPrefix + act, type: 'POST', dataType: 'json' };
		if (isUpload) {
			payload.append('csrf', csrf);
			payload.append('uid', uid);
			options.data = payload; options.processData = false; options.contentType = false;
		} else {
			options.data = $.extend({ csrf: csrf, uid: uid }, payload || {});
		}
		options.success = function (res) {
			if (loading !== null) layer.close(loading);
			if (res.code !== 0) { toast(res.msg || '操作失败'); return; }
			toast(res.msg, true);
			done && done(res.data);
		};
		options.error = function () {
			if (loading !== null) layer.close(loading);
			toast('网络错误，请重试');
		};
		$.ajax(options);
	}
	function formData() {
		var data = {};
		$.each($form.serializeArray(), function (_, item) { data['form[' + item.name + ']'] = item.value; });
		return data;
	}
	function fillSelect($select, options, placeholder, value) {
		var html = '<option value="">' + esc(placeholder) + '</option>';
		$.each(options || [], function (_, item) {
			html += '<option value="' + esc(item.value) + '">' + esc(item.label) + '</option>';
		});
		$select.html(html);
		if (value) $select.val(value);
	}
	function loadRegions(province, city, preset) {
		request('regions', { provinceCode: province || '', cityCode: city || '' }, function (data) {
			if (!province) {
				fillSelect($form.find('[name=provinceCode]'), data.province, '省', preset && preset.provinceCode);
				if (preset && preset.provinceCode) loadRegions(preset.provinceCode, '', preset);
			} else if (!city) {
				fillSelect($form.find('[name=cityCode]'), data.city, '市', preset && preset.cityCode);
				if (preset && preset.cityCode) loadRegions(province, preset.cityCode, preset);
			} else {
				fillSelect($form.find('[name=countyCode]'), data.county, '区县', preset && preset.countyCode);
			}
		});
	}
	function toggleSubject() {
		var subject = $form.find('[name=subjectType]').val();
		$form.find('[data-kpay-license]').toggle(subject !== 'personal');
	}
	function renderMaterials(data) {
		var uploaded = (data.view && data.view.uploaded) || [];
		var missing = {};
		$.each((data.view && data.view.missingMaterials) || [], function (_, item) { missing[item.materialType] = true; });
		var html = '';
		$.each(data.materials, function (type, label) {
			var done = uploaded.indexOf(type) >= 0;
			var mark = done ? '<span class="label label-success">已上传</span>' : (missing[type] ? '<span class="label label-danger">需上传</span>' : '<span class="label label-default">按需</span>');
			html += '<tr><td>' + esc(label) + '</td><td>' + mark + '</td><td class="text-right"><button type="button" class="btn btn-xs btn-default" data-kpay-upload="' + esc(type) + '">' + (done ? '重新上传' : '上传') + '</button></td></tr>';
		});
		$('#kpay-materials').html(html);
	}
	function render(data) {
		state.data = data;
		var view = data.view;
		var apply = data.apply;
		var $notice = $('#kpay-notice').hide();
		if (!data.configured) {
			$notice.text('进件功能尚未配置，请联系平台。').show();
		} else if (!data.selfService) {
			$notice.text('暂未开放在线申请，请联系平台。').show();
		} else if (data.viewError) {
			$notice.text(data.viewError).show();
		}
		$form.find('[data-kpay-alipay]').toggle(!!data.hasAlipay);

		var editable = data.configured && data.selfService && (!view || view.editable || state.startNew);
		$form.find('input,select').prop('disabled', !editable);
		$form.find('[data-kpay-act=save]').toggle(editable);

		if (apply && view && !state.startNew) {
			$('#kpay-status-panel').show();
			$('#kpay-status-label').text(view.statusLabel || apply.statusLabel);
			$('#kpay-progress').text(view.status === 'draft' ? '资料完成度 ' + view.progressPercent + '%' : '');
			$('#kpay-remark').toggle(!!view.reviewRemark).text(view.reviewRemark || '');
			var items = [];
			$.each(view.missingFields || [], function (_, item) { items.push('<li>' + esc(item) + '</li>'); });
			$.each(view.missingMaterials || [], function (_, item) { items.push('<li>' + esc(item.label) + '</li>'); });
			$('#kpay-missing').toggle(items.length > 0 && view.editable);
			$('#kpay-missing-list').html(items.join(''));
			var links = [];
			$.each(view.confirmUrls || [], function (i, url) { links.push('<li><a href="' + esc(url) + '" target="_blank" rel="noopener">确认链接 ' + (i + 1) + '</a></li>'); });
			$('#kpay-confirm').toggle(links.length > 0);
			$('#kpay-confirm-list').html(links.join(''));
			$('#kpay-submit-btn').toggle(!!view.canSubmit && data.selfService);
			$('#kpay-new-btn').toggle(!!data.canStartNew && data.selfService);
			$('#kpay-bound').toggle(!!apply.bindPid).text(apply.bindPid ? '已绑定收款账户 ' + apply.bindPid : '');
			var approved = ['approved', 'partial_approved', 'partially_approved'].indexOf(view.status) >= 0;
			$('#kpay-bind-panel').toggle(approved && data.userBind);
			fillForm(view.form || {});
		} else {
			$('#kpay-status-panel').toggle(!!apply && !state.startNew);
			$('#kpay-bind-panel').hide();
			if (!$form.data('regions-loaded')) { loadRegions('', '', null); $form.data('regions-loaded', 1); }
		}
		toggleSubject();
		renderMaterials(data);
	}
	function fillForm(values) {
		$.each(values, function (name, value) {
			var $field = $form.find('[name=' + name + ']');
			if ($field.length && !$field.is('select[name=provinceCode],select[name=cityCode],select[name=countyCode]')) $field.val(value);
		});
		loadRegions('', '', values);
		$form.data('regions-loaded', 1);
	}

	$form.on('change', '[name=subjectType]', toggleSubject);
	$form.on('change', '[name=provinceCode]', function () {
		fillSelect($form.find('[name=cityCode]'), [], '市');
		fillSelect($form.find('[name=countyCode]'), [], '区县');
		if (this.value) loadRegions(this.value, '', null);
	});
	$form.on('change', '[name=cityCode]', function () {
		fillSelect($form.find('[name=countyCode]'), [], '区县');
		if (this.value) loadRegions($form.find('[name=provinceCode]').val(), this.value, null);
	});
	$root.on('click', '[data-kpay-act]', function () {
		var act = $(this).data('kpay-act');
		if (act === 'save') {
			var payload = formData();
			if (state.startNew) payload.startNew = 1;
			request('save', payload, function (data) { state.startNew = false; render(data); });
		} else if (act === 'bank') {
			request('bank', formData(), render);
		} else if (act === 'bind') {
			request('bind', { pid: $('#kpay-bind-pid').val(), key: $('#kpay-bind-key').val() }, render);
		} else if (act === 'submit') {
			if (!confirm('提交后资料不能再修改，确认提交？')) return;
			request('submit', {}, render);
		} else {
			request(act, {}, render);
		}
	});
	$('#kpay-new-btn').on('click', function () {
		state.startNew = true;
		render(state.data);
	});
	$root.on('click', '[data-kpay-upload]', function () {
		if (!state.data || !state.data.apply) { toast('请先保存资料'); return; }
		state.pendingMaterial = $(this).data('kpay-upload');
		$('#kpay-file').val('').trigger('click');
	});
	$('#kpay-file').on('change', function () {
		if (!this.files || !this.files[0]) return;
		var payload = new FormData();
		payload.append('materialType', state.pendingMaterial);
		payload.append('file', this.files[0]);
		request('upload', payload, render, true);
	});

	request('detail', {}, render);
})(jQuery);
</script>
<?php
}
