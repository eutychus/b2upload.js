<?php

if(!empty($_REQUEST['action'])) {
	$redis = new Redis();
	$redis->pconnect('127.0.0.1');

	require_once("lcmB2.php");

	$allowFile = function($name, $size) {
		return "test2" . basename($name);
	};

	// action, accountId, applicationKey, redis, function to check if we want to allow the upload
	b2Rest($_REQUEST['action'], "accountId", "applicationKey", $redis, $allowFile);
}
