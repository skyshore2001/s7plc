# S7Plc

A php lib to read/write Siements S7 PLC series like S7-1200/S7-1500 via S7 protocol.

Usage (level 1): read/write once (short connection)

```php
require("common.php");
require("S7Plc.php");
try {
	S7Plc::writePlc("192.168.1.101", [["DB21.0:int32", 70000], ["DB21.4:float", 3.14]]);

	$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int32", "DB21.4:float"]);
	var_dump($res);
	// on success $res=[ 70000, 3.14 ]
}
catch (S7PlcException $ex) {
	echo($ex->getMessage());
}
```

Usage (level 2): read and write in one connection (long connection)

```php
try {
	$plc = new S7Plc("192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$plc->write([["DB21.0:int32", 70000], ["DB21.4:float", 3.14]]);
	$res = $plc->read(["DB21.0:int32", "DB21.4:float"]);
	// on success $res=[ 30000, 3.14 ]
}
catch (S7PlcException $ex) {
	echo($ex->getMessage());
}
```
