# S7Plc

A php lib to read/write Siements S7 PLC series like S7-1200/S7-1500 via S7 protocol.

Related projects:

- [plc-access](https://github.com/skyshore2001/plc-access/): A php command-line tool to read/write PLC via Siements S7 protocol or modbus tcp protocol.
- [plcserver](https://github.com/skyshore2001/plcserver/): PLC access service that supports to read/write/**watch and callback** via http web service

Usage (level 1): read/write once (short connection)

```php
require("common.php");
require("S7Plc.php");
try {
	S7Plc::writePlc("192.168.1.101", [["DB21.0:int32", 70000], ["DB21.4:float", 3.14], ["DB21.12.0:bit", 1]]);

	$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	var_dump($res);
	// on success $res=[ 70000, 3.14, 1 ]
}
catch (S7PlcException $ex) {
	echo('error: ' . $ex->getMessage());
}
```

Usage (level 2): read and write in one connection (long connection)

```php
try {
	$plc = new S7Plc("192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$plc->write([["DB21.0:int32", 70000], ["DB21.4:float", 3.14], ["DB21.12.0:bit", 1]]);
	$res = $plc->read(["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	// on success $res=[ 30000, 3.14, 1 ]
}
catch (S7PlcException $ex) {
	echo('error: ' . $ex->getMessage());
}
```

**Read/write array**

```php
$plc->write(["DB21.0:int8[4]", "DB21.4:float[2]"], [ [1,2,3,4], [3.3, 4.4] ]);
$res = $plc->read(["DB21.0:int8[4]", "DB21.4:float[2]"]);
// $res example: [ [1,2,3,4], [3.3, 4.4] ]
```

OR

```php
S7Plc::writePlc("192.168.1.101", ["DB21.0:int8[4]", "DB21.4:float[2]"], [ [1,2,3,4], [3.3, 4.4] ]);
$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int8[4]", "DB21.4:float[2]"]);
```

It's ok to contain both array and elements:

```php
S7Plc::writePlc("192.168.1.101", ["DB21.0:int8[4]", "DB21.4:float", "DB21.8:float"], [ [1,2,3,4], 3.3, 4.4 ]);
$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int8[4]", "DB21.4:float", "DB21.8:float"]);
// $res example: [ [1,2,3,4], 3.3, 4.4 ]
```

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
- char

TODO:

- byte order definition.
- just test on S7-1200 series. (S7-200 uses different connection param like rack)

