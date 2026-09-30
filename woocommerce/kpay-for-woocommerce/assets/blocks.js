/* KPay 凯付：区块结账里的付款方式（不需要构建，直接使用 WordPress 提供的全局对象） */
(function () {
	var wc = window.wc || {};
	var wp = window.wp || {};
	if (!wc.wcBlocksRegistry || !wc.wcSettings || !wp.element) {
		return;
	}
	var el = wp.element.createElement;
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities ? wp.htmlEntities.decodeEntities : function (s) { return s; };
	var settings = wc.wcSettings.getSetting('kpay_data', {});
	var title = decode(settings.title || 'KPay 凯付');

	function Content() {
		return el('div', null, decode(settings.description || ''));
	}

	wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'kpay',
		label: el('span', null, title),
		ariaLabel: title,
		content: el(Content),
		edit: el(Content),
		canMakePayment: function () { return true; },
		supports: { features: settings.supports || ['products'] }
	});
})();
