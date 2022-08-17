<?php

require("common.php");
require("S7Plc.php");
try {
	S7Plc::writePlc("192.168.1.101", [["DB21.0:int32", 70000], ["DB21.4:float", 3.14], ["DB678.28.0:bit", 1]]);

	$res = S7Plc::readPlc("192.168.1.101", ["DB21.0:int32", "DB21.4:float", "DB678.28.0:bit"]);
	var_dump($res);
	// on success $res=[ 70000, 3.14, 1 ]
}
catch (S7PlcException $ex) {
	echo('error: ' . $ex->getMessage());
}

/*
// Usage (level 2): read and write in one connection (long connection)

try {
	$plc = new S7Plc("192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$plc->write([["DB21.0:int32", 70000], ["DB21.4:float", 3.14]]);
	$res = $plc->read(["DB21.0:int32", "DB21.4:float"]);
	// on success $res=[ 30000, 3.14 ]
}
catch (S7PlcException $ex) {
	echo('error: ' . $ex->getMessage());
}
*/
