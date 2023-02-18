<?php

class PlcAccessException extends LogicException 
{
}

class PlcAccess
{
	static protected $typeAlias = [
		"bool" => "bit",
		"byte" => "uint8",
		"word" => "uint16",
		"dword" => "uint32",
		"int" => "int16",
		"dint" => "int32"
	];

	static function readPlc($proto, $addr, $items) {
		$plc = PlcAccess::create($proto, $addr);
		return $plc->read($items);
	}
	static function writePlc($proto, $addr, $items) {
		$plc = PlcAccess::create($proto, $addr);
		return $plc->write($items);
	}

	// $plc = PlcAccess::create("s7", "192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	static function create($proto, $addr) {
		if ($proto == 's7') {
			require_once("S7Plc.php");
			return new S7Plc($addr);
		}
		else if ($proto == 'modbus') {
			require_once("ModbusClient.php");
			return new ModbusClient($addr);
		}
		throw new PlcAccessException("unknown proto $proto");
	}

	// [ ["DB100.0:byte"] ] => [ ["code"=>"DB100.0", "type"=>"byte", "isArray"=>false, "amount"=>1] ]
	function read($items) {
		$items1 = [];
		foreach ($items as $addr) {
			$item = $this->parseItem($addr);
			$items1[] = $item;
		}
		return $items1;
	}

	// [ ["DB100.0:byte", 11] ] => [ ["code"=>"DB100.0", "type"=>"byte", "amount"=>1, "isArray"=>false, "value"=>11] ]
	function write($items) {
		$items1 = [];
		foreach ($items as $e) {
			$item = $this->parseItem($e[0], $e[1]);
			$items1[] = $item;
		}
		return $items1;
	}

	// item: {code, type, isArray, amount}
	protected function readItem($item, $packFmt, $value0) {
		$t = $item["type"];
		if ($t == "char") {
			if ($item["amount"] == strlen($value0)) {
				$value = $value0;
			}
			else {
				$value = substr($value0, 0, $item["amount"]);
			}
		}
		else if ($t == "string") {
			$rv = unpack("C2", substr($value0, 0, 2));
			$cap = $rv[1];
			$strlen = $rv[2];
			if ($strlen < strlen($value0) - 2) {
				$value = substr($value0, 2, $strlen);
			}
			else {
				$value = substr($value0, 2);
			}
		}
		else if (! $item["isArray"]) {
			$value = unpack($packFmt, $value0)[1];
		}
		else { // 数组
			$rv = unpack($packFmt.$item["amount"], $value0);
			$value = array_values($rv);
		}
		self::fixInt($item["type"], $value);
		return $value;
	}

	// item: {code, type, isArray, amount, value}
	protected function writeItem($item, $packFmt) {
		$t = $item["type"];
		if ($t == "char" || $t == "string") {
			$valuePack = $item["value"];
		}
		else if ($item["isArray"]) { // 数组处理
			$valuePack = '';
			foreach ($item["value"] as $v) {
				$valuePack .= pack($packFmt, $v);
			}
		}
		else {
			$valuePack = pack($packFmt, $item["value"]);
		}
		return $valuePack;
	}

	protected static function getTcpConn($addr, $defaultPort) {
		if (strpos($addr, ':') === false)
			$addr .= ":" . $defaultPort;
		@$fp = fsockopen("tcp://" . $addr, null, $errno, $errstr, 3); // connect timeout=3s
		if ($fp === false) {
			$error = "fail to open tcp connection to `$addr`, error $errno: $errstr";
			throw new PlcAccessException($error);
		}
		stream_set_timeout($fp, 3, 0); // read timeout=3s
		return $fp;
	}

	// return: {code, type, isArray, amount, value?}
	protected function parseItem($itemAddr, $value = null) {
		if (! preg_match('/^(?<code>.*):(?<type>\w+) (?:\[(?<amount>\d+)\])?$/x', $itemAddr, $ms)) {
			$error = "bad plc item addr: `$itemAddr`";
			throw new PlcAccessException($error);
		}
		if (array_key_exists($ms["type"], self::$typeAlias)) {
			$ms["type"] = self::$typeAlias[$ms["type"]];
		}
		$item = [
			"code"=>$ms["code"],
			"type"=>$ms["type"],
			"isArray" => isset($ms["amount"]),
			"amount" => (@$ms["amount"]?:1)
		];
		if ($value !== null) {
			// char and string is specical!
			if ($item["type"] == "char") {
				$diff = $item["amount"] - strlen($value);
				if ($diff > 0) { // pad 0 if not enough
					$value .= str_repeat("\x00", $diff);
				}
				else if ($diff < 0) { // trunk if too long
					$value = substr($value, 0, $item["amount"]);
				}
			}
			else if ($item["type"] == "string") {
				$diff = $item["amount"] - strlen($value);
				if ($diff < 0) { // trunk if too long
					$value = substr($value, 0, $item["amount"]);
				}
				$value = pack("CC", $item["amount"], strlen($value)) . $value;
				$item["amount"] = strlen($value);
			}
			else if ($item["isArray"]) {
				if (! is_array($value)) {
					$error = "require array value for $itemAddr";
					throw new PlcAccessException($error);
				}
				// 自动截断或补0
				$diff = $item["amount"] - count($value);
				// $error = "bad array amount for $itemAddr";
				if ($diff < 0) {
					$value = array_slice($value, 0, $item["amount"]);
				}
				else if ($diff > 0) {
					while ($diff-- != 0) {
						$value[] = 0;
					}
				}
			}
			$item["value"] = $value;
		}
		else {
			if ($item["type"] == "string") {
				$item["amount"] += 2;
			}
		}
		return $item;
	}

	// 无符号转有符号
	protected static function fixInt($type, &$value) {
		if (is_array($value)) {
			foreach ($value as &$v) {
				self::fixInt($type, $v);
			}
			unset($v);
			return;
		}
		if ($type == "int16") {
			if ($value > 0x8000)
				$value -= 0x10000;
		}
		else if ($type == "int32") {
			if ($value > 0x80000000)
				$value -= 0x100000000;
		}
	}
}
