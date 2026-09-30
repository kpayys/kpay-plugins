<?php

/**
 * 测试替身：内存版的彩虹易支付 $DB，以及记录请求、返回预设响应的 KPay 客户端
 */

class FakeDB
{
	public $tables = ['config' => [], 'channel' => [], 'subchannel' => [], 'kpay_apply' => [], 'user' => []];
	public $execLog = [];
	private $ids = [];

	public function getColumn($sql, $params = [])
	{
		$row = $this->getRow($sql, $params);
		return $row ? reset($row) : false;
	}

	public function getRow($sql, $params = [])
	{
		$rows = $this->select($sql, $params);
		return $rows ? $rows[0] : false;
	}

	public function getAll($sql, $params = [])
	{
		return $this->select($sql, $params);
	}

	public function exec($sql, $params = [])
	{
		$this->execLog[] = $sql;
		if (preg_match('/^REPLACE INTO pre_config SET/i', trim($sql))) {
			if (isset($params[':k'])) {
				$this->tables['config'][$params[':k']] = ['k' => $params[':k'], 'v' => $params[':v']];
			} elseif (preg_match("/k='([^']+)'/", $sql, $m)) {
				$this->tables['config'][$m[1]] = ['k' => $m[1], 'v' => $params[':v']];
			}
		}
		return 1;
	}

	public function insert($table, $data)
	{
		$this->ids[$table] = isset($this->ids[$table]) ? $this->ids[$table] + 1 : 1;
		$data = array_map([$this, 'value'], $data);
		$data['id'] = $this->ids[$table];
		foreach (['application_no', 'status_label', 'activation_status', 'merchant_code', 'review_remark', 'summary', 'bind_pid', 'bound_at', 'updatetime'] as $column) {
			if ($table === 'kpay_apply' && !array_key_exists($column, $data)) {
				$data[$column] = null;
			}
		}
		$this->tables[$table][$data['id']] = $data;
		return $data['id'];
	}

	public function update($table, $data, $where)
	{
		$count = 0;
		foreach ($this->tables[$table] as $id => $row) {
			$match = true;
			foreach ($where as $column => $value) {
				if ((string)$row[$column] !== (string)$value) {
					$match = false;
				}
			}
			if ($match) {
				$this->tables[$table][$id] = array_merge($row, array_map([$this, 'value'], $data));
				$count++;
			}
		}
		return $count;
	}

	private function value($value)
	{
		return $value === 'NOW()' ? '2026-10-01 12:00:00' : $value;
	}

	private function select($sql, $params)
	{
		if (preg_match('/FROM pre_config WHERE k=:k/i', $sql)) {
			return isset($this->tables['config'][$params[':k']]) ? [['v' => $this->tables['config'][$params[':k']]['v']]] : [];
		}
		if (preg_match("/FROM pre_config WHERE k='([^']+)'/i", $sql, $m)) {
			return isset($this->tables['config'][$m[1]]) ? [['v' => $this->tables['config'][$m[1]]['v']]] : [];
		}
		if (preg_match('/FROM pre_(\w+) WHERE (.+?)( ORDER BY id DESC)? LIMIT 1/i', $sql, $m)) {
			$rows = array_values($this->tables[$m[1]]);
			preg_match_all('/(\w+)=(:\w+)/', $m[2], $conditions, PREG_SET_ORDER);
			$rows = array_values(array_filter($rows, function ($row) use ($conditions, $params) {
				foreach ($conditions as $condition) {
					if ((string)$row[$condition[1]] !== (string)$params[$condition[2]]) {
						return false;
					}
				}
				return true;
			}));
			if (!empty($m[3])) {
				$rows = array_reverse($rows);
			}
			return $rows ? [$rows[0]] : [];
		}
		throw new Exception('FakeDB 不认识的查询：' . $sql);
	}
}

class FakeKpayClient extends KpayClient
{
	public static $requests = [];
	public static $responses = [];

	protected function send($method, $url, $body, array $headers)
	{
		$parts = parse_url($url);
		$uri = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$named = [];
		foreach ($headers as $header) {
			list($name, $value) = explode(':', $header, 2);
			$named[strtolower($name)] = trim($value);
		}
		self::$requests[] = ['method' => $method, 'uri' => $uri, 'body' => $body, 'headers' => $named];
		$key = $method . ' ' . $parts['path'];
		if (!isset(self::$responses[$key]) || !self::$responses[$key]) {
			throw new Exception('没有预设响应：' . $key);
		}
		return ['status' => 200, 'body' => json_encode(array_shift(self::$responses[$key]), JSON_UNESCAPED_UNICODE)];
	}

	public static function reset()
	{
		self::$requests = [];
		self::$responses = [];
	}

	public static function respond($method, $path, array $payload)
	{
		self::$responses[$method . ' ' . $path][] = $payload;
	}

	public static function last()
	{
		return end(self::$requests);
	}
}

class TestKpayService extends KpayService
{
	public function client()
	{
		return new FakeKpayClient($this->setting('apiurl'), $this->setting('apikey'), $this->setting('apisecret'));
	}

	public function epayClient()
	{
		return new FakeKpayClient($this->setting('apiurl'));
	}
}

/**
 * 按服务端规则重新计算请求签名，确认客户端发出的签名头能过验签
 */
function hmac_request_valid(array $request, $secret)
{
	$h = $request['headers'];
	$bodyHash = hash('sha256', (string)$request['body']);
	if (!isset($h['x-kpay-body-sha256']) || $h['x-kpay-body-sha256'] !== $bodyHash) {
		return false;
	}
	$canonical = implode("\n", [$request['method'], $request['uri'], $h['x-kpay-timestamp'], $h['x-kpay-nonce'], $bodyHash]);
	return hash_equals(hash_hmac('sha256', $canonical, $secret), $h['x-kpay-signature']);
}
