<?php

/*
@class S7Plc
@author liangjian <liangjian@oliveche.com>

Usage (level 1): read/write once (short connection)

	try {
		S7Plc::writePlc("192.168.1.101", [["DB21.0:int32", 70000], ["DB21.4:float", 3.14]]);

		$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int32", "DB21.4:float"]);
		// on success $res=[ 70000, 3.14 ]
	}
	catch (S7PlcException $ex) {
		echo($ex->getMessage());
	}

Usage (level 2): read and write in one connection (long connection)

	try {
		$plc = new S7Plc("192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
		$plc->write([["DB21.0:int32", 70000], ["DB21.4:float", 3.14]]);
		$res = $plc->read(["DB21.0:int32", "DB21.4:float"]);
		// on success $res=[ 70000, 3.14 ]
	}
	catch (S7PlcException $ex) {
		echo($ex->getMessage());
	}

Read Request/Response Packet:
(refer to: s7_micro_client.cpp opReadMultiVars/opWriteMultiVars)

	TPKT 4B
	COTP 3B
	S7ReqHeader 10B
	 ...
	 Sequence
	 ParamLen
	 DataLength
	ReqParams
	  FunctionCode 1B
	  ItemCount 1B
	  @Items
	  @Data
	 ...


	TPKT 4B
	COTP 3B
	S7ResHeader 12B
	ResParams 2B
	 FunctionCode
	 ItemCount
	 @Items
	 ...
*/

class S7PlcException extends LogicException 
{
}

class S7Plc
{
	protected $addr;
	protected $fp;

	static protected $typeAlias = [
		"bool" => "bit",
		"byte" => "uint8",
		"word" => "uint16",
		"dword" => "uint32",
		"int" => "int16",
		"dint" => "int32"
	];
	static protected $typeMap = [
		// WordLen: S7WLBit=0x01; S7WLByte=0x02; S7WLChar=0x03; S7WLWord=0x04; S7WLDWord=0x06; S7WLReal=0x08;
		// TransportSize: TS_ResBit=0x03, TS_ResByte=0x04, TS_ResInt=0x05, TS_ResReal=0x07, TS_ResOctet=0x09
		"bit" => ["fmt"=>"C", "len"=>1, "WordLen"=>0x01, "TransportSize"=>0x03],
		"int8" => ["fmt"=>"c", "len"=>1, "WordLen"=>0x02, "TransportSize"=>0x04],
		"uint8" => ["fmt"=>"C", "len"=>1, "WordLen"=>0x02, "TransportSize"=>0x04],

		"int16" => ["fmt"=>"n", "len"=>2, "WordLen"=>0x04, "TransportSize"=>0x05],
		"uint16" => ["fmt"=>"n", "len"=>2, "WordLen"=>0x04, "TransportSize"=>0x05],

		"int32" => ["fmt"=>"N", "len"=>4, "WordLen"=>0x06, "TransportSize"=>0x05],
		"uint32" => ["fmt"=>"N", "len"=>4, "WordLen"=>0x06, "TransportSize"=>0x05],

		"float" => ["fmt"=>"f", "len"=>4, "WordLen"=>0x08, "TransportSize"=>0x07],
		"char" => ["fmt"=>"a", "len"=>1, "WordLen"=>0x03, "TransportSize"=>0x09]
		// "double" => ["fmt"=>"?", "len"=>8, "WordLen"=>0x0?, "TransportSize"=>0x0?],
		// "string[]"
	];

	function __construct($addr) {
		$this->addr = $addr;
	}

	function __destruct() {
		if ($this->fp) {
			fclose($this->fp);
			$this->fp = null;
		}
	}

	protected function getConn() {
		if ($this->fp === null) {
			$addr = $this->addr;
			if (strpos($addr, ':') === false)
				$addr .= ":102"; // default s7 port
			@$fp = fsockopen("tcp://" . $addr, null, $errno, $errstr, 3); // connect timeout=3s
			if ($fp === false) {
				$error = "fail to open tcp connection to `$addr`, error $errno: $errstr";
				throw new S7PlcException($error);
			}
			stream_set_timeout($fp, 3, 0); // read timeout=3s
			$this->fp = $fp;
		}
		return $this->fp;
	}

	// items: ["DB21.0:int32", "DB21.4:float", "DB21.0.0:bit"]
	function read($items) {
		$items1 = [];
		foreach ($items as $addr) {
			$item = $this->parseItem($addr);
			$items1[] = $item;
		}

		$readPacket = $this->buildReadPacket($items1);
		$res = $this->isoExchangeBuffer($readPacket, $pos);

		$ResParams = myunpack(substr($res, $pos, 2), [
			"C", "FunRead",
			"C", "ItemCount"
		]);
		if ($ResParams["ItemCount"] != count($items)) {
			$error = 'bad server item count: ' . $ResParams["ItemCount"];
			throw new S7PlcException($error);
		}
		$pos += 2;

		// S7DataItem
		$ret = [];
		for ($i = 0; $i < count($items1); $i++) {
			$ResData = myunpack(substr($res, $pos, 10), [
				"C", "ReturnCode",
				"C", "TransportSize",
				"n", "DataLen",
				// data
			]);
			$retCode = $ResData["ReturnCode"];
			if ($retCode != 0xff) { // <-- 0xFF means Result OK
				$error = "fail to read `{$items[$i]}`: return code=$retCode";
				throw new S7PlcException($error);
			}
			$len = $ResData['DataLen'];
			if ($ResData['TransportSize'] != 0x09 /* TS_ResOctet */
					&& $ResData['TransportSize'] != 0x07 /* TS_ResReal */
					&& $ResData['TransportSize'] != 0x03 /* TS_ResBit */
				) {
				$len /= 8; // bit数转byte数
			}
			$pos += 4;
			$value = substr($res, $pos, $len);
			$type = $items1[$i]["type"];
			$fmt = self::$typeMap[$type]["fmt"];
			$amount = $items1[$i]["amount"];
			if ($amount > 1) { // 数组
				$value1 = array_values(unpack($fmt . $amount, $value));
			}
			else {
				$value1 = unpack($fmt, $value)[1];
			}
			if ($type == "int16") {
				if ($value1 > 0x8000)
					$value1 -= 0x10000;
			}
			else if ($type == "int32") {
				if ($value1 > 0x80000000)
					$value1 -= 0x100000000;
			}
			$ret[] = $value1;

			if ($len % 2 != 0) {
				++ $len;  // Skip fill byte for Odd frame
			}
			$pos += $len;
		};
		return $ret;
	}

	// items: [ ["DB21.0:int32", 70000], ["DB21.4:float", 3.14] ]
	// refer to: opWriteMultiVars (snap7 lib)
	function write($items) {
		$items1 = [];
		foreach ($items as $item) { // item: [addr, value]
			$item1 = $this->parseItem($item[0], $item[1]);
			$items1[] = $item1;
		}
		$writePacket = $this->buildWritePacket($items1);
		$res = $this->isoExchangeBuffer($writePacket, $pos);

		$ResParams = myunpack(substr($res, $pos, 2), [
			"C", "FunWrite",
			"C", "ItemCount"
		]);
		if ($ResParams["ItemCount"] != count($items)) {
			$error = 'bad server item count: ' . $ResParams["ItemCount"];
			throw new S7PlcException($error);
		}

		$pos += 2;
		$data = unpack("C".count($items), substr($res, $pos));

		$i = 0;
		foreach ($data as $retCode) {
			if ($retCode != 0xff) { // <-- 0xFF means Result OK
				$error = "fail to write `{$items[$i][0]}`: return code=$retCode";
				throw new S7PlcException($error);
			}
			++ $i;
		}
	}

	static function readPlc($addr, $items) {
		$plc = new S7Plc($addr);
		return $plc->read($items);
	}
	static function writePlc($addr, $items) {
		$plc = new S7Plc($addr);
		return $plc->write($items);
	}

	// items: [{ dbNumber, type=int8/int16/int32/float/double, startAddr, amount, value }]
	protected function buildReadPacket($items) {
		$ReqParams = mypack([
			"C", 0x04, // FunRead=pduFuncRead
			"C", count($items), // ItemsCount
		]);
		foreach ($items as $item) {
			$t = $item["type"];
			$ReqFunReadItem = mypack([
				"C", 0x12,
				"C", 0x0A,
				"C", 0x10,
				"C", self::$typeMap[$t]["WordLen"],
				"n", $item["amount"],
				"n", $item["dbNumber"],
				// "C", 0x84, // area: S7AreaDB; 位置: 8
				"N", (0x84000000 | ($item["startAddr"] * 8 + $item["bit"])) // 起始地址，按字节计转按位计。注意：这里需要修正，它只用了3B，与上1字节一起是4B
			]);
			$ReqParams .= $ReqFunReadItem;
		}

		$S7ReqHeader = mypack([
			"C", 0x32, // Telegram ID, always 32
			"C", 0x01, // PduType_request
			"n", 0, // AB_EX: AB currently unknown, maybe it can be used for long numbers.
			"n", 0, // Sequence; // Message ID. This can be used to make sure a received answer; TODO: GetNextWord
			"n", strlen($ReqParams), // Length of parameters which follow this header
			"n", 0 // DataLen: Length of data which follow the parameters; 0: No data in the read request
		]);
		$payload = $S7ReqHeader . $ReqParams;
		$TPKT = mypack([
			"C", 3, // version: isoTcpVersion
			"C", 0, // reserved: 0
			"n", strlen($payload)+7, // length + header length
		]);
		$COTP = mypack([
			"C", 2, // headerLength(下面2B)
			"C", 0xF0, // PDUType: pdu_type_DT
			"C", 0x80, // EoT_Num: pdu_EoT
		]);

		return $TPKT . $COTP . $payload;
	}

	// items: [{ code, dbNumber, type=int8/int16/int32/float/double, startAddr, amount }]
	protected function buildWritePacket($items) {
		$itemCnt = count($items);
		$ReqParams = mypack([
			"C", 0x05, // FunWrite=pduFuncWrite
			"C", $itemCnt, // ItemsCount
		]);
		$ReqData = '';
		$idx = 0;
		foreach ($items as $item) {
			$t = $item["type"];
			// TReqFunWriteItem
			$ReqFunWriteItem = mypack([
				"C", 0x12,
				"C", 0x0A,
				"C", 0x10,
				"C", self::$typeMap[$t]["WordLen"],
				"n", $item["amount"],
				"n", $item["dbNumber"],
				// "C", 0x84, // area: S7AreaDB; 位置: 8
				"N", (0x84000000 | ($item["startAddr"] * 8 + $item["bit"])) // 起始地址，按字节计转按位计。注意：这里需要修正，它只用了3B，与上1字节一起是4B
			]);
			$ReqParams .= $ReqFunWriteItem;
			// ReqFunWriteDataItem 值在所有WriteItem之后
			$fmt = self::$typeMap[$t]["fmt"];
			if ($item["amount"] > 1) { // 数组处理
				$valuePack = '';
				foreach ($item["value"] as $v) {
					$valuePack .= pack($fmt, $v);
				}
			}
			else {
				$valuePack = pack($fmt, $item["value"]);
			}
			$TransportSize = self::$typeMap[$t]["TransportSize"];
			$size = $item["amount"] * self::$typeMap[$t]["len"]; // byte count
			$len = $size;
			if ($TransportSize != 0x09 /* TS_ResOctet */
					&& $TransportSize != 0x07 /* TS_ResReal */
					&& $TransportSize != 0x03 /* TS_ResBit */
				) {
				$len *= 8; // byte转bit
			}
			$ReqData .= mypack([
				"C", 0x00,  // ReturnCode
				"C", $TransportSize, 
				"n", $len,
			]) . $valuePack;
			// Skip fill byte for Odd frame (except for the last one)
			$idx ++;
			if (($size % 2) != 0 && $idx != $itemCnt) {
				$ReqData .= "\x00";
			}
		}
		$S7ReqHeader = mypack([
			"C", 0x32, // Telegram ID, always 32
			"C", 0x01, // PduType_request
			"n", 0, // AB_EX: AB currently unknown, maybe it can be used for long numbers.
			"n", 0, // Sequence; // Message ID. This can be used to make sure a received answer; TODO: GetNextWord
			"n", strlen($ReqParams), // Length of parameters which follow this header
			"n", strlen($ReqData) // DataLen: Length of data which follow the parameters
		]);
		$payload = $S7ReqHeader . $ReqParams . $ReqData;
		$TPKT = mypack([
			"C", 3, // version: isoTcpVersion
			"C", 0, // reserved: 0
			"n", strlen($payload)+7, // length + header length
		]);
		$COTP = mypack([
			"C", 2, // headerLength(下面2B)
			"C", 0xF0, // PDUType: pdu_type_DT
			"C", 0x80, // EoT_Num: pdu_EoT
		]);

		return $TPKT . $COTP . $payload;
	}

	// $pos: ResParam开始位置
	protected function isoExchangeBuffer($req, &$pos) {
		$fp = $this->getConn();
		$rv = fwrite($fp, $req);

		$res = fread($fp, 4096);
		if (!$res) {
			$error = "read timeout or receive null response";
			throw new S7PlcException($error);
		}

		$version = unpack("C", $res[0])[1]; // TPKT check
		if ($version != 3) {
			$error = "bad response: bad protocol";
			throw new S7PlcException($error);
		}
		$payloadSize = unpack("n", substr($res,2,2))[1]; // TODO: check size
		// TODO: 包可能没收全

		$pos = 7; // TPKT+COTP
		$S7ResHeader23 = myunpack(substr($res, $pos, 12), [
			"C", "P", // Telegram ID, always 0x32
			"C", "PDUType", // Header type 2 or 3
			"n", "AB_EX",
			"n", "Sequence",
			"n", "ParamLen",
			"n", "DataLen",
			"n", "Error"
		]);
		if ($S7ResHeader23['Error']!=0) {
			$error = 'server returns error: ' . $S7ResHeader23['Error'];
			throw new S7PlcException($error);
		}
		$pos += 12;

		return $res;
	}

	// return: {code, dbNumber, startAddr, type, amount}
	protected function parseItem($itemAddr, $value = null) {
		if (! preg_match('/^DB(?<db>\d+) \.(?<addr>\d+) (?:\.(?<bit>\d+))? :(?<type>\w+) (?:\[(?<amount>\d+)\])?$/x', $itemAddr, $ms)) {
			$error = "bad plc item addr: `$itemAddr`";
			throw new S7PlcException($error);
		}
		if (array_key_exists($ms["type"], self::$typeAlias)) {
			$ms["type"] = self::$typeAlias[$ms["type"]];
		}
		else if (! array_key_exists($ms["type"], self::$typeMap)) {
			$error = "unknown plc item type: `$itemAddr`";
			throw new S7PlcException($error);
		}
		$bit = @$ms["bit"];
		if ($bit !== "") {
			if ($ms["type"] != "bit") {
				$error = "require bit type for `$itemAddr`";
				throw new S7PlcException($error);
			}
		}
		else {
			$bit = 0;
		}

		$item1 = [
			"code" => $itemAddr,
			"dbNumber"=>$ms["db"],
			"startAddr"=>$ms["addr"],
			"bit"=>$bit,
			"type"=>$ms["type"],
			"amount" => (@$ms["amount"]?:1)
		];

		if ($value !== null) {
			if ($item1["amount"] > 1) {
				if (! is_array($value)) {
					$error = "require array value for $itemAddr";
					throw new S7PlcException($error);
				}
				if (count($value) != $item1["amount"]) {
					$error = "bad array amount for $itemAddr";
					throw new S7PlcException($error);
				}
				if ($item1["type"] == "bit") {
					foreach($value as &$v) {
						$v = $v ? 1: 0;
					}
				}
			}
			else {
				if ($item1["type"] == "bit") {
					$value = $value ? 1: 0;
				}
			}
			$item1["value"] = $value;
		}
		return $item1;
	}
}

