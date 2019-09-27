<?php
// Requires PHP 7
// Requires curl extension
// Redis+phpredis recommended (or modify to use another caching engine)

class lcmB2 {
	private $accountId;
	private $applicationKey;
	private $authTimeout;
	public  $authTTL = 600;
	private $baseURL = "https://api.backblazeb2.com/b2api/v2/";
	private $authInfo;

	private $redis = false;
	public $redisPrefix = "b2:";

	public function __construct($accountId, $applicationKey, $redis = false) {
		$this->accountId = $accountId;
		$this->applicationKey = $applicationKey;
		$this->redis = $redis;
	}

	// simple session based data store with TTL
	// only used when redis is not available
	// only used for small file tokens
	private function setSession($key, $val, $ttl = 0) {
		if (session_status() == PHP_SESSION_NONE) {
			session_start();
		}

		$_SESSION[$redisPrefix . $key] = $val;
		$_SESSION[$redisPrefix . ":exp:" . $key] = (empty($ttl) ? 0 : time()+intval($ttl));
		return true;
	}

	private function getSession($key, $val) {
		if (session_status() == PHP_SESSION_NONE) {
			session_start();
		}

		if(!empty($_SESSION[$redisPrefix . ":exp:" . $key]) && time() > intval($_SESSION[$redisPrefix . ":exp:" . $key])) {
			unset($_SESSION[$redisPrefix . ":exp:" . $key]);
			return false;
		}

		else if(isset($_SESSION[$redisPrefix . $key])) return $_SESSION[$redisPrefix . $key];
	}

	public function authorizeAccount($usecache = true) {
		if($usecache && $this->redis) {
			$auth = $this->redis->get("{$this->redisPrefix}auth:".$this->accountId);
			if($auth) {
				$this->authInfo = json_decode($auth,true);
				return 2;
			}
		}

		$ch = curl_init($this->baseURL . "b2_authorize_account");
		$headers = [
			"Accept: application/json",
			"Authorization: Basic " . base64_encode($this->accountId . ":" . $this->applicationKey)
		];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec($ch);
		curl_close ($ch);

		$this->authInfo = json_decode($server_output,true);

		// cache account authorization for 12 hours
		if($this->redis) $this->redis->set("{$this->redisPrefix}auth:" . $this->accountId, $server_output, 12*60*60);
		//var_dump($this->authInfo);
	}

	private function isBadToken($obj) {
		if(!is_array($obj) || empty($out['status']) || empty($out['code'])) return false;
		if($out['status'] == 401 && ($out['code'] == "bad_auth_token" || $out['code'] == "expired_auth_token")) return true;
		return false;
	}


	public function finishLargeFile($fileId, $fileSize, $sha1Array = []) {
		$fileSize = intval($fileSize);
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		$parts = $this->listParts($fileId, false);
		if(isset($parts['code'])) return false;

		$parts2=[];
		$max = 0;
		$totalSize = 0;

		for($i = 0; $i < count($parts); $i++) {
			$parts2[$parts[$i]['partNumber']] = [ $parts[$i]['contentLength'], $parts[$i]['contentSha1'] ];
			if($parts[$i]['partNumber'] > $max) $max = $parts[$i]['partNumber'];
			if($parts[$i]['partNumber'] == 1) $chunkSize = $parts[$i]['contentLength'];
			$totalSize += $parts[$i]['contentLength'];
		}

		if($totalSize !== $fileSize) return false;

		if(empty($sha1Array)) {
			foreach($parts2 as $partNo => $val) {
				$sha1Array[] = $val[1];
			}
		}

		$options = ["fileId" => $fileId, "partSha1Array" => $sha1Array];

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_finish_large_file");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);

		$out = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($out)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->finishLargeFile($fileId, $fileSize);
		}

		$sendFields = ["status", "code", "message", "action", "contentLength", "contentType", "fileId", "fileInfo", "fileName", "uploadTimestamp"];
		foreach($out as $key => $val) {
			if(!in_array($key, $sendFields)) unset($out[$key]);
		}

		return $out;

	}

	public function checkLargeFile($filename, $options = []) {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		if(empty($options)) {
			$options['bucketId'] = $this->authInfo['allowed']['bucketId'];
		}
		$options['namePrefix'] = $filename;

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_list_unfinished_large_files");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);

		$out = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($out)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->checkLargeFile($filename, $options);
		}
		else return $out;
	}

	public function uploadFile($filename, $options = [], $fileinfo = []) {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;


		if(empty($options)) {
			$options['bucketId'] = $this->authInfo['allowed']['bucketId'];
			$options['contentType'] = "b2/x-auto";
		}
		$options['fileName'] = $filename;
		$options['fileInfo'] = $fileinfo;

	}

	public function startLargeFile($filename, $options = [], $fileinfo = []) {
		// not yet implemented
		if($this->redis) {
			$rkey = "{$this->redisPrefix}uploadtoken:" . sha1($filename);
			$val = $this->redis->get($rkey);
			if($val) return json_decode($val,true);
		}

		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		if(empty($options)) {
			$options['bucketId'] = $this->authInfo['allowed']['bucketId'];
			$options['contentType'] = "b2/x-auto";
		}
		$options['fileName'] = $filename;
		$options['fileInfo'] = $fileinfo;

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_start_large_file");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);

		$out = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($out)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->startLargeFile($filename, $options, $fileinfo);
		}
		else return $out;
	}

	public function getBucketInfo($bucketId = "") {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		if(empty($bucketId)) $bucketId = $this->authInfo['allowed']['bucketId'];
		$options = [
			"accountId" => $this->authInfo['accountId'],
			"bucketId" => $bucketId
		];

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_list_buckets");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);
		return json_decode($json,true);

	}

	public function updateBucketCors($bucketId) {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		$cors = <<<EOF
[
    {
      "corsRuleName": "resumableUpload",
      "allowedOrigins": [
        "https"
      ],
      "allowedHeaders": ["*"],
      "allowedOperations": [
        "b2_download_file_by_id",
        "b2_download_file_by_name",
        "b2_upload_file",
        "b2_upload_part"
      ],
      "exposeHeaders": ["x-bz-content-sha1"],
      "maxAgeSeconds": 3600
    }
]
EOF;

		$cores = json_decode($cors, true);
		$options = [
			"accountId" => $this->authInfo['accountId'],
			"bucketId" => $bucketId,
			"corsRules" => $cores
		];

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_update_bucket");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);
		return json_decode($json,true);
	}

	public function getUploadPartUrl($fileId) {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_get_upload_part_url");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["fileId" => $fileId]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);
		$out = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($out)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->getUploadPartUrl($fileId);
		}
		else return $out;
	}

	public function getUploadUrl($bucketId = "") {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		if(empty($bucketId)) $bucketId = $this->authInfo['allowed']['bucketId'];

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_get_upload_url");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["bucketId" => $bucketId]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);
		$out = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($out)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->getUploadUrl($bucketId);
		}
		// out contains bucketId, uploadUrl, authorizationToken
		else return $out;
	}

	public function listFileVersions($filename, $bucketId = "") {
		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;

		if(empty($bucketId)) $bucketId = $this->authInfo['allowed']['bucketId'];

		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_list_file_versions");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["bucketId" => $bucketId, "prefix" => $filename]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);
		$out = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($out)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->listFileVersions($fileId, $bucketId);
		}
		else return $out;
	}

	public function listParts($fileId, $useCache = true) {
		if($useCache && $this->redis) {
			// we should probably use a redis set here for faster lookup
			$parts = $this->redis->get("parts:".$fileId);
			if(!empty($parts)) return json_decode($parts,true);
		}

		if(empty($this->authInfo)) $this->authorizeAccount();
		if(empty($this->authInfo)) return false;
		$ch = curl_init($this->authInfo['apiUrl'] . "/b2api/v2/b2_list_parts");
		$headers = [ "Accept: application/json", "Authorization: " . $this->authInfo['authorizationToken'] ];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["fileId" => $fileId]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$json = curl_exec($ch);
		curl_close($ch);
		$parts = json_decode($json,true);

		// auto retry for expired token
		if($this->isBadToken($parts)) {
			$this->authorizeAccount(false);
			if(empty($this->authInfo)) return false;
			return $this->listParts($fileId, false);
		}

		if(isset($parts['parts'])) {
			if($this->redis) $this->redis->set("{$this->redisPrefix}parts:".$fileId, json_encode($parts['parts']), 60*60);
			return $parts['parts'];
		}

		return $parts;
	}

	public function partExists($fileId, $partNo) {
		$parts = $this->listParts($fileId,true);
		foreach($parts as $p) {
			if(!isset($p['partNumber'])) continue;
			if($p['partNumber'] == $partNo) return true;
		}
		return false;
	}

	public function matchFileVersion($filename, $size, $bucketId = "") {
		$versions = $this->listFileVersions($filename, $bucketId);
		if($versions === false) return ["status" => 401];
		if(isset($versions['files'])) {
			foreach($versions['files'] as $f) {
				if($f['fileName'] !== $filename) continue;
				// backblaze uses "action=upload" for any file that is complete
				if($f['action'] == "upload" && $f['contentLength'] == $size) {
					return ["status" => 200, "fileId" => $f['fileId'], "action" => "upload"];
				}
				// backblaze uses "action=start" for a large file started but not closed
				if($f['action'] == "start" && isset($f['fileInfo']['src_size']) && $f['fileInfo']['src_size'] == $size) {
					return ["status" => 206, "fileId" => $f['fileId'], "action" => "start"];
				}
			}
			return ["status" => 404];
		}
		else return $versions;
	}

	public function authSmallFile($fname, $fileSize, $lastModified, $bucketId = "", $url = "api.php?action=uploadSmallFile") {
		$key = sha1($fname."-".$fileSize."-".random_bytes(128));

		$val = [
			"fileName" => $fname,
			"fileSize" => $fileSize,
			"lastModified" => $lastModified
		];
		if(!empty($bucketId)) $val['bucketId'] = $bucketId;

		// small files don't resume... so valid for 1 hour
		if($this->redis) $this->redis->set("{$this->redisPrefix}smallFile:".$key, json_encode($val), 60*60*1);
		else $this->setSession("{$this->redisPrefix}smallFile:".$key, json_encode($val), 60*60*1);

		return ["bucketId" => "", "uploadUrl" => $url, "authorizationToken" => $key, "fileId" => $key];
	}

	//public function uploadSmallFile($sfAuth,

	public function acceptSmallFile() {
		// WARN: This function directly uses _REQUEST variables and php://input

		//var_dump($_SERVER);
		$key = "{$this->redisPrefix}smallFile:".$_SERVER['HTTP_AUTHORIZATION'];
		if($this->redis) $val = $this->redis->get($key);
		else $val = $this->getSession($key);
		if(!$val) return false;

		$val = json_decode($val,true);
		if(!isset($val['fileName'])) return false;

		//$tmp = tmpfile();
		//file_put_contents("upload/outputfile.txt", file_get_contents("php://input"));
		//fwrite($tmp, file_get_contents("php://input"));
		$uploadAuth = $this->getUploadUrl();
		if(!isset($uploadAuth['authorizationToken'])) return $uploadAuth;

		$headers = [
			"Authorization" => $uploadAuth['authorizationToken'],
			"Content-Length" => $_SERVER['CONTENT_LENGTH'],
			"X-Bz-File-Name" => rawurlencode($val['fileName']),
			"Content-Type" => "b2/x-auto",
			"X-Bz-Content-Sha1" => $_SERVER['HTTP_X_BZ_CONTENT_SHA1'],
			"X-Bz-Info-src_last_modified_millis" => $val['lastModified'], // optional

		];
		$this->uploadRawSocket($uploadAuth['uploadUrl'], "php://input", $headers);

		//$this->uploadRawSocket("https://localhost/b2upload/api.php?action=uploadTest", "php://input", ["Content-Length", $val['fileSize']]);
		//fclose($tmp);

		var_dump($val);
	}

	public function uploadRawSocket($url, $infile, $headers) {
		$url = parse_url($url);
		if($url['scheme'] == "https") {
			if(empty($url['port'])) $url['port'] = 443;
			$sockpath = "tls://{$url['host']}:" . $url['port'];
		}
		else {
			if(empty($url['port'])) $url['port'] = 80;
			$sockpath = "tcp://" . $url['host'] . ":" . $url['port'];
		}

		$path = $url['path'];
		if(!empty($url['query'])) $path .= "?" . $url['query'];


		$req = "POST {$path} HTTP/1.1\r\n";
		$req .= "Host: {$url['host']}\r\n";
		$req .= "Content-Type: application/octet-stream\r\n";
		//$req .= "Content-Length: " . $_SERVER['CONTENT_LENGTH'] . "\r\n";

		foreach($headers as $key => $val) {
			$req .= trim($key) . ": " . trim($val) . "\r\n";
		}
		$req .= "Connection: close\r\n\r\n";

		$fp = stream_socket_client($sockpath, $errno, $errstr, 30);
		if(!$fp) return false;

		fputs($fp, $req);

		$in = fopen($infile, "r");
		$ok = true;
		while($in && $ok && !feof($in)) {
			$buf = fread($in, 8192);
			$ok = fwrite($fp, $buf);
			error_log("uploadRawSocket " . strlen($buf) . ", " . $ok . " bytes\n");
		}

		$res = "";
		while($fp && !feof($fp)) { $res .= fgets($fp, 128); }
		error_log("raw post result: " . $res);
		fclose($fp);
	}
}

function b2Rest($action, $accountId, $applicationKey, $redis = false, $testFileFunction = false) {
	// All functions here directly use superglobals (e.g. _SERVER, _REQUEST)
	// php://input is also used for small files

	$b2 = new lcmB2($accountId, $applicationKey, $redis);

	/*
	// commented for security
	// your b2 bucket must have proper cors/permissions for direct uploads

	if($action == "getBucketInfo") {
		header("Content-Type: text/plain");
		var_dump($b2->getBucketInfo());
	}

	if($action == "updateBucketCors") {
		$bucketId = "";
		var_dump($b2->updateBucketCors($bucketId));
	}
	*/

	// test code for validating use of uploadRawSocket
	// keep commented except for testing
	/*
	if($_REQUEST['action'] == "uploadTest") {
		header("Content-Type: text/plain");
		var_dump($_SERVER);

		$contents = file_get_contents("php://input");
		$in = fopen("php://input", "r");
		error_log("opened input");
		$fp = fopen("upload/outfile.bin", "w");
		$ok = true;
		while($in && $ok && !feof($in)) {
			$buf = fread($in, 65536);
			error_log("read " . strlen($buf) . " bytes");
			fwrite($fp, $buf);
		}
		fclose($fp);
		fclose($in);
	}
	*/

	if($action == "checkFile") {
		if(strlen($_REQUEST['fileId']) == 40) {
			// TODO: check for existance of small file
			$exists = false;
		}
		else $exists = $b2->partExists($_REQUEST['fileId'], $_SERVER['HTTP_X_BZ_PART_NUMBER']);

		if($exists) {
			echo json_encode("part exists");
			http_response_code(200);
		}
		else http_response_code(204);
		exit;
	}

	if($action == "finishFile") {
		if(empty($_REQUEST['fileId']) || empty($_REQUEST['fileSize'])) {
			http_response_code(400);
			die(json_encode(["status" => 400]));
		}
		else echo json_encode($b2->finishLargeFile($_REQUEST['fileId'], $_REQUEST['fileSize']));
		exit;
	}

	if($action == "uploadSmallFile") {
		if(empty($_SERVER['HTTP_X_BZ_CONTENT_SHA1']) || empty($_SERVER['CONTENT_LENGTH'])) {
			http_response_code(400);
			die(json_encode(["status" => 400]));
		}
		else {
			$b2->acceptSmallFile();
		}
		exit
	}

	if($action == "checkServer") {
		header("Content-Type: text/plain");
		echo "PHP Max Post Size: " . ini_get('post_max_size') . "\n";
	}

	if($action == "authFile") {
		// for files smaller than 5MB, upload in a single part to server, then pass to backblaze
		$largeSize = 5000000;

		if(empty($_POST['b2LastModified']) || empty($_POST['b2FileSize']) || empty($_POST['b2FileName']) || empty(basename($_POST['b2FileName']))) {
			http_response_code(400);
			die(json_encode(["status" => 400]));
		}

		$fileSize = intval($_POST['b2FileSize']);

		if(is_callable($testFileFunction)) {
			$fname = $testFileFunction($_POST['b2FileName'], $fileSize);
			if(empty($fname)) {
				http_response_code(403);
				echo json_encode(["status" => 403]);
			}
		}
		// default to allow file, but place it under test
		else $fname = "test/" . basename($_POST['b2FileName']);

		//$unfinished = $b2->checkLargeFile($fname);
		$m = $b2->matchFileVersion($fname, $fileSize);
		if(isset($m['status'])) {
			error_log(print_r($m,true));
			// file with matching name and size exists... deny upload
			if($m['status'] == 200) {
				$m['status'] = 409; // 409 status indicates conflict
			}
			else if($m['status'] == 206) {
				// 206 is partial upload
				$b2->listParts($m['fileId'], false);
				$largefile = $m;
			}
			else if($m['status'] == 404 && $fileSize > $largeSize) {
				$largefile = $b2->startLargeFile($fname, [], [
					"src_last_modified_millis" => $_POST['b2LastModified'],
					"src_size" => $_POST['b2FileSize']
				]);
			}
			else if($m['status'] == 404) {
				// backblaze single file upload token is insecure
				// it doesn't allow setting the path
				// for small uploads, we simulate the API, then push server-side
				// server and PHP must accept post up to 5MB
				$auth = $b2->authSmallFile($fname, $fileSize, $_POST['b2LastModified']);
				if(!$auth) {
					http_response_code(500);
					echo json_encode(["status" => 500]);
				}
				else echo json_encode($auth);
				exit;
			}
			else $largefile = $m;

			if($m['status'] > 399 && $m['status'] !== 404) die(json_encode($m));
			// we only use the fileId field, which should be set with a 2xx code
		}
		// we shouldn't get here
		else die(json_encode(["status" => 500]));

		error_log(json_encode($largefile));

		if($fileSize > $largeSize) {
			if(!$largefile || !is_array($largefile) || !isset($largefile['fileId'])) {
				http_response_code(502);
				die("false");
			}

			$part = $b2->getUploadPartUrl($largefile['fileId']);
			if(!$part) {
				http_response_code(502);
				die("false");
			}
		}

		echo json_encode($part);

	}

}
