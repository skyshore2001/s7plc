<?php

/*
@class S7Plc
@author liangjian <liangjian@oliveche.com>

Usage: read and write

	try {
		$plc = PlcAccess::create("s7", "192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
		$plc->write([["DB21.0:int32", 70000], ["DB21.4:float", 3.14]]);
		$res = $plc->read(["DB21.0:int32", "DB21.4:float"]);
		// on success $res=[ 70000, 3.14 ]
	}
	catch (PlcAccessException $ex) {
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

class S7Plc extends PlcAccess
{
	protected $addr;
	protected $fp;

	static protected $typeMap = [
		// WordLen: S7WLBit=0x01; S7WLByte=0x02; S7WLChar=0x03; S7WLWord=0x04; S7WLDWord=0x06; S7WLReal=0x08;
		// TransportSize: TS_ResBit=0x03, TS_ResByte=0x04(byte/word/dword), TS_ResInt=0x05, TS_ResReal=0x07, TS_ResOctet=0x09
		"bit" => ["fmt"=>"C", "len"=>1, "WordLen"=>0x01, "TransportSize"=>0x03],
		"int8" => ["fmt"=>"c", "len"=>1, "WordLen"=>0x02, "TransportSize"=>0x04],
		"uint8" => ["fmt"=>"C", "len"=>1, "WordLen"=>0x02, "TransportSize"=>0x04],

		"int16" => ["fmt"=>"n", "len"=>2, "WordLen"=>0x04, "TransportSize"=>0x04],
		"uint16" => ["fmt"=>"n", "len"=>2, "WordLen"=>0x04, "TransportSize"=>0x04],

		"int32" => ["fmt"=>"N", "len"=>4, "WordLen"=>0x06, "TransportSize"=>0x04],
		"uint32" => ["fmt"=>"N", "len"=>4, "WordLen"=>0x06, "TransportSize"=>0x04],

		"float" => ["fmt"=>"f", "len"=>4, "WordLen"=>0x08, "TransportSize"=>0x07],
		"char" => ["fmt"=>"a", "len"=>1, "WordLen"=>0x03, "TransportSize"=>0x09],
		"string" => ["fmt"=>"a", "len"=>1, "WordLen"=>0x03, "TransportSize"=>0x09]
		// "double" => ["fmt"=>"?", "len"=>8, "WordLen"=>0x0?, "TransportSize"=>0x0?],
	];

	const pdu_type_CR    	= 0xE0;  // Connection request
	const pdu_type_CC    	= 0xD0;  // Connection confirm
	const pdu_type_DT    	= 0xF0;  // Data transfer
	const pdu_type_DR    	= 0x80;  // Disconnect request
	const pdu_type_DC    	= 0xC0;  // Disconnect confirm

	function __construct($addr) {
		$this->addr = $addr;
	}

	function __destruct() {
		if ($this->fp) {
			fclose($this->fp);
			$this->fp = null;
		}
	}

	// items: ["DB21.0:int32", "DB21.4:float", "DB21.0.0:bit"]
	function read($items) {
		$items1 = parent::read($items);
		$readPacket = $this->buildReadPacket($items1);
		$res = $this->isoExchangeBuffer($readPacket, $pos);

		$ResParams = myunpack(substr($res, $pos, 2), [
			"C", "FunRead",
			"C", "ItemCount"
		]);
		if ($ResParams["ItemCount"] != count($items)) {
			$error = 'bad server item count: ' . $ResParams["ItemCount"];
			throw new PlcAccessException($error);
		}
		$pos += 2;

		// S7DataItem
		$ret = [];
		foreach ($items1 as $item) {
			$ResData = myunpack(substr($res, $pos, 10), [
				"C", "ReturnCode",
				"C", "TransportSize",
				"n", "DataLen",
				// data
			]);
			$retCode = $ResData["ReturnCode"];
			if ($retCode != 0xff) { // <-- 0xFF means Result OK
				$error = "fail to read `{$item['code']}`: return code=$retCode";
				throw new PlcAccessException($error);
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
			$type = $item["type"];
			$packFmt = self::$typeMap[$type]["fmt"];

			$value1 = $this->readItem($item, $packFmt, $value);
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
		$items1 = parent::write($items);
		$writePacket = $this->buildWritePacket($items1);
		$res = $this->isoExchangeBuffer($writePacket, $pos);

		$ResParams = myunpack(substr($res, $pos, 2), [
			"C", "FunWrite",
			"C", "ItemCount"
		]);
		if ($ResParams["ItemCount"] != count($items)) {
			$error = 'bad server item count: ' . $ResParams["ItemCount"];
			throw new PlcAccessException($error);
		}

		$pos += 2;
		$data = unpack("C".count($items), substr($res, $pos));

		$i = 0;
		foreach ($data as $retCode) {
			if ($retCode != 0xff) { // <-- 0xFF means Result OK
				$error = "fail to write `{$items[$i][0]}`: return code=$retCode";
				throw new PlcAccessException($error);
			}
			++ $i;
		}
	}

	// items: [{ dbNumber, type=int8/int16/int32/float/double, dbAddr, amount }]
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
				"N", (0x84000000 | ($item["dbAddr"] * 8 + $item["bit"])) // 起始地址，按字节计转按位计。注意：这里需要修正，它只用了3B，与上1字节一起是4B
			]);
			$ReqParams .= $ReqFunReadItem;
		}

		$S7ReqHeader = mypack([
			"C", 0x32, // Telegram ID, always 32
			"C", 0x01, // PduType_request
			"n", 0, // AB_EX: AB currently unknown, maybe it can be used for long numbers.
			"n", 1, // Sequence; // Message ID. This can be used to make sure a received answer; TODO: GetNextWord
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

	// items: [{ code, dbNumber, type=int8/int16/int32/float/double, dbAddr, amount }]
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
				"N", (0x84000000 | ($item["dbAddr"] * 8 + $item["bit"])) // 起始地址，按字节计转按位计。注意：这里需要修正，它只用了3B，与上1字节一起是4B
			]);
			$ReqParams .= $ReqFunWriteItem;
			// ReqFunWriteDataItem 值在所有WriteItem之后
			$packFmt = self::$typeMap[$t]["fmt"];

			$valuePack = $this->writeItem($item, $packFmt);
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
		if ($this->fp === null) {
			$this->fp = self::getTcpConn($this->addr, 102); // default s7 port
			$this->isoConnect();
		}
		$fp = $this->fp;
		$rv = fwrite($fp, $req);

		$res = fread($fp, 4096);
		if (!$res) {
			$error = "read timeout or receive null response";
			throw new PlcAccessException($error);
		}

		$version = unpack("C", $res[0])[1]; // TPKT check
		if ($version != 3) {
			$error = "bad response: bad protocol";
			throw new PlcAccessException($error);
		}
		$payloadSize = unpack("n", substr($res,2,2))[1]; // TODO: check size

		$pos = 7; // TPKT+COTP
		$reqPduType = ord($req[5]);
		if ($reqPduType == self::pdu_type_DT) { // data transfer
			// TODO: 包可能没收全

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
				throw new PlcAccessException($error);
			}
			$pos += 12;
		}

		return $res;
	}

	// return: {code, type, isArray, amount, value?, dbNumber, dbAddr, bit}
	protected function parseItem($itemAddr, $value = null) {
		$item = parent::parseItem($itemAddr, $value);
		if (! preg_match('/^DB(?<db>\d+) \.(?<dbAddr>\d+) (?:\.(?<bit>\d+))? $/x', $item["code"], $ms)) {
			$error = "bad S7Plc item: `{$item['code']}`";
			throw new PlcAccessException($error);
		}
		if (! array_key_exists($item["type"], self::$typeMap)) {
			$error = "unsupport S7Plc item type: `$itemAddr`";
			throw new PlcAccessException($error);
		}
		$bit = @$ms["bit"];
		if (isset($bit)) {
			if ($item["type"] != "bit") {
				$error = "require bit type for `$itemAddr`";
				throw new PlcAccessException($error);
			}
		}
		else {
			$bit = 0;
		}

		$item["dbNumber"] = $ms["db"];
		$item["dbAddr"] = $ms["dbAddr"];
		$item["bit"] = $bit;
		return $item;
	}

	protected function isoConnect() {
		$COTP = mypack([
			"C", 17, // headerLength
			"C", self::pdu_type_CR, // connect request
			"n", 0, // dest reference
			"n", 1, // src reference
			"C", 0, // flags

			"C", 0xc0, // parameter code=tpdu-size
			"C", 1, // param len
			"C", 0x0a, // value=1024

			"C", 0xc1, // parameter src-tsap
			"C", 2, // len
			"n", 0x0102, // value

			"C", 0xc2, // parameter dst-tsap
			"C", 2, // len
			"n", 0x0100, // value
		]);
		$TPKT = mypack([
			"C", 3, // version: isoTcpVersion
			"C", 0, // reserved: 0
			"n", strlen($COTP) +4, // length + header length
		]);
		$req = $TPKT . $COTP;

		$res = $this->isoExchangeBuffer($req, $pos);
		$resPDUType = ord($res[5]);
		if ($resPDUType != self::pdu_type_CC) { //Connect Confirm
			$error = 'bad response package. require pdu_type_CC package';
			throw new PlcAccessException($error);
		}

		// NegotiatePDULength
		$ReqParams = mypack([
			"C", 0xf0, // setup comunnication
			"C", 0x00, // reserved
			"n", 1, // parallel jobs with ack calling: 1
			"n", 1, // parallel jobs with ack called: 1
			"n", 480 // PDU length
		]);
		$S7Req = mypack([
			"C", 0x32, // Telegram ID, always 32
			"C", 0x01, // PduType_request
			"n", 0, // AB_EX: AB currently unknown, maybe it can be used for long numbers.
			"n", 1024, // protocol data unit reference
			"n", strlen($ReqParams), // Length of parameters which follow this header
			"n", 0 // DataLen: Length of data which follow the parameters
			// data
		]) . $ReqParams;
		$COTP = mypack([
			"C", 2, // headerLength
			"C", self::pdu_type_DT, // data transfer
			"C", 0x80, // flag
		]);
		$TPKT = mypack([
			"C", 3, // version: isoTcpVersion
			"C", 0, // reserved: 0
			"n", strlen($COTP) + strlen($S7Req) + 4, // length + header length
		]);
		$req = $TPKT . $COTP . $S7Req;

		$res = $this->isoExchangeBuffer($req, $pos);
		$resPDUType = ord($res[5]);
		if ($resPDUType != self::pdu_type_DT) { // data transfer package
			$error = 'bad response package. require pdu_type_DT package';
			throw new PlcAccessException($error);
		}
	}
}

