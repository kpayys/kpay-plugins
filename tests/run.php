<?php
/**
 * 无依赖测试：php tests/run.php
 *
 * vectors.json 里的签名由独立实现（按 KPay 服务端的签名规则）算出，
 * 插件里的签名函数必须和它逐条一致。
 */

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$GLOBALS['__failures'] = 0;
$GLOBALS['__passes'] = 0;

function check($condition, $label)
{
	if ($condition) {
		$GLOBALS['__passes']++;
		return;
	}
	$GLOBALS['__failures']++;
	fwrite(STDERR, "FAIL: {$label}\n");
}

function throws($callback)
{
	try {
		$callback();
	} catch (Throwable $e) {
		return $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
	}
	return false;
}

$vectors = json_decode((string)file_get_contents(__DIR__ . '/vectors.json'), true);
define('VECTORS', $vectors);
define('VECTOR_KEY', $vectors['key']);
define('VECTOR_CASES', $vectors['cases']);

require __DIR__ . '/acg_test.php';
require __DIR__ . '/epay_test.php';
require __DIR__ . '/whmcs_test.php';
require __DIR__ . '/woocommerce_test.php';
require __DIR__ . '/zjmf_test.php';
require __DIR__ . '/shopxo_test.php';
require __DIR__ . '/sdk_php_test.php';
if (PHP_VERSION_ID >= 80000) {
	require __DIR__ . '/superpay_test.php';
	require __DIR__ . '/paymenter_test.php';
} else {
	echo "超级支付、Paymenter 需要 PHP 8，跳过\n";
}

echo "{$GLOBALS['__passes']} passed, {$GLOBALS['__failures']} failed\n";
exit($GLOBALS['__failures'] > 0 ? 1 : 0);
