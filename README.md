# S7Plc

A php lib to read/write Siements S7 PLC series like S7-1200/S7-1500 via S7 protocol.

Related projects:

- [plc-access](https://github.com/skyshore2001/plc-access/): A php command-line tool to read/write PLC via Siements S7 protocol or modbus tcp protocol.
- [plcserver](https://github.com/skyshore2001/plcserver/): PLC access service that supports to read/write/**watch and callback** via http web service

## Usage

Usage (level 1): read/write once (short connection)

```php
require("common.php");
require("PlcAccess.php");

try {
	PlcAccess::writePlc("s7", "192.168.1.101", [
		["DB21.0:int32", 70000],
		["DB21.4:float", 3.14],
		["DB21.12.0:bit", 1]
	]);

	$res = PlcAccess::readPlc("s7", "192.168.1.101", ["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	var_dump($res);
	// on success $res=[ 70000, 3.14, 1 ]
}
catch (PlcAccessException $ex) {
	echo('error: ' . $ex->getMessage());
}
```

Usage (level 2): read and write in one connection (long connection)

```php
try {
	$plc = PlcAccess::create("s7", "192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$plc->write([
		["DB21.0:int32", 70000],
		["DB21.4:float", 3.14],
		["DB21.12.0:bit", 1]
	]);
	$res = $plc->read(["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	// on success $res=[ 30000, 3.14, 1 ]
}
catch (PlcAccessException $ex) {
	echo('error: ' . $ex->getMessage());
}
```

## Read/write array

```php
$plc->write([
	["DB21.0:int8[4]", [1,2,3,4]],
	["DB21.4:float[2]", [3.3, 4.4]
]);
$res = $plc->read(["DB21.0:int8[4]", "DB21.4:float[2]"]);
// $res example: [ [1,2,3,4], [3.3, 4.4] ]
```

If element count is less than the specified count, 0 is padded; 
or truncated if element count is greater.

	$plc->write([ ["DB21.0:int8[4]", [1,2]] ]); // equal to set [1,2,0,0]
	$plc->write([ ["DB21.0:int8[4]", []] ]); // all 4 clear to 0

It's ok to contain both array type and non-array type:

```php
$plc->write([
	["DB21.0:int8[4]", [3,4]],
	["DB21.4:float", 3.3],
	["DB21.8:float", 4.4]
]);
$res = $plc->read(["DB21.0:int8[4]", "DB21.4:float", "DB21.8:float"]);
// $res example: [ [1,2,3,4], 3.3, 4.4 ]
```

## Read/write string

Type `char[capacity]` is fixed-length string:

	$plc->write([ ["DB21.0:char[4]", "abcd"] ]);
	$res = $plc->read(["DB21.0:char[4]"]);

Note: the max capacity for the fixed-length string is 256.

You can write any chars including non-printing ones. It will pad 0 if the string length is not enough, or truncate to capacity if too long.

	$plc->write([ ["DB21.0:char[4]", "\x01\x02\x03"] ]); // actually write "\x01\x02\x03\x00"
	$plc->write([ ["DB21.0:char[4]", "abcdef"] ]); // actually write "abcd"

Type `string[capacity]` is variable-length string, compatible with Siemens S7 string (1 byte capacity + 1 byte length + chars). The max capacity is 254.

	$plc->write([ ["DB21.0:string[4]", "ab"] ]); // actually write "\x04\x02ab"
	$res = $plc->read(["DB21.0:string[4]"]); // result is "ab"

For variable-length string, all chars (capacity) are read and just return string with the actual length.

## Reference

**Address Example**

- DB21.DBB4 (byte): DB21.4:int8 (-127~127) or DB21.4:uint8 (0~256)
- DB21.DBW4 (word): DB21.4:int16 (-32767~32768) or DB21.4:uint16 (0~65536)
- DB21.DBD4 (dword): DB21.4:int32 or DB21.4:uint32 or DB21.4:float
- DB21.DBX4.0 (bit): DB21.4.0:bit

**Support types:**

- int8
- uint8/byte
- int16/int
- uint16/word
- int32/dint
- uint32/dword
- bit/bool
- float
- char[capacity]
- string[capacity]

## TODO

- byte order definition.
- just test on S7-1200 series. (S7-200 uses different connection param like rack)
