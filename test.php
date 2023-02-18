<?php

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

/*
// Usage (level 2): read and write in one connection (long connection)

try {
	$plc = PlcAccess::create("s7", "192.168.1.101"); // default tcp port 102: "192.168.1.101:102"
	$plc->write([
		["DB21.0:int32", 70000],
		["DB21.4:float", 3.14],
		["DB21.12.0:bit", 1]
	]);
	$res = $plc->read(["DB21.0:int32", "DB21.4:float", "DB21.12.0:bit"]);
	// on success $res=[ 30000, 3.14, 1 ]
	var_dump($res);
}
catch (PlcAccessException $ex) {
	echo('error: ' . $ex->getMessage());
}
*/
