<?php
/** Synthetic protocol tests; the fake cannot open a socket. */
namespace phpseclib3\Net {
	if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
	class SFTP {
		const SOURCE_LOCAL_FILE = 1;
		const RESUME = 4;
		public static $files = [];
		public static $logins = 0;
		public static $interrupt = false;
		public static $key = 'ssh-ed25519 c3ludGhldGljLWhvc3Qta2V5';
		public function __construct($host,$port,$timeout) {}
		public function getServerPublicHostKey() { return self::$key; }
		public function login($username,$password) { self::$logins++; return $username === 'synthetic-user' && $password === 'synthetic-test-only#[]{},"quotes'; }
		public function file_exists($path) { return isset(self::$files[$path]); }
		public function clearStatCache() {}
		public function filesize($path) { return isset(self::$files[$path]) ? strlen(self::$files[$path]) : false; }
		public function get($path,$unused,$offset,$length) { return isset(self::$files[$path]) ? substr(self::$files[$path],$offset,$length) : false; }
		public function put($path,$local,$mode) {
			$content = file_get_contents($local);
			if (self::$interrupt) { self::$interrupt = false; self::$files[$path] = substr($content,0,3); return false; }
			$offset = ($mode & self::RESUME) ? strlen(self::$files[$path]) : 0;
			self::$files[$path] = substr(self::$files[$path] ?? '',0,$offset).substr($content,$offset); return true;
		}
		public function rename($from,$to) { if (isset(self::$files[$to])) { return false; } self::$files[$to] = self::$files[$from]; unset(self::$files[$from]); return true; }
	}
}
namespace {
	require_once __DIR__.'/../lib/Config.php';
	require_once __DIR__.'/../lib/SftpTransport.php';
	function verify($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
	function fails($call,$expected) {
		try { $call(); } catch (\MDPNPreservation\Failure $e) { verify(strpos($e->getMessage(),$expected) !== false,$e->getMessage()); return; }
		throw new RuntimeException('Expected failure: '.$expected);
	}
	$root = realpath(sys_get_temp_dir()).'/mdpn-sftp-'.bin2hex(random_bytes(8)); mkdir($root,0700);
	try {
		$credential = $root.'/credentials.json'; file_put_contents($credential,json_encode(['sftp_username' => 'synthetic-user','sftp_password' => 'synthetic-test-only#[]{},"quotes'])); chmod($credential,0600);
		$values = ['credentials_file' => $credential,'sftp_host' => 'ingest.example.org','sftp_port' => 22,'sftp_directory' => '/incoming',
			'sftp_host_key_sha256' => 'SHA256:'.rtrim(base64_encode(hash('sha256','synthetic-host-key',true)),'=')];
		$wrong = $values; $wrong['sftp_host_key_sha256'] = 'SHA256:'.str_repeat('A',43);
		fails(function() use ($wrong) { new \MDPNPreservation\SftpTransport(new \MDPNPreservation\Config($wrong)); },'server identity');
		verify(\phpseclib3\Net\SFTP::$logins === 0,'Credentials must not be sent before server verification');
		$transport = new \MDPNPreservation\SftpTransport(new \MDPNPreservation\Config($values));
		$local = $root.'/EXAMPLE_test.tar'; file_put_contents($local,'synthetic archive bytes');
		$part = ['filename' => basename($local),'sha256' => hash_file('sha256',$local)];
		\phpseclib3\Net\SFTP::$interrupt = true;
		fails(function() use ($transport,$local,$part) { $transport->send($local,$part); },'interrupted');
		verify(!isset(\phpseclib3\Net\SFTP::$files['/incoming/EXAMPLE_test.tar']),'Partial uploads must not be finalized');
		$transport->send($local,$part);
		verify(\phpseclib3\Net\SFTP::$files['/incoming/EXAMPLE_test.tar'] === file_get_contents($local),'Resume and remote byte verification');
		$transport->send($local,$part);
		\phpseclib3\Net\SFTP::$files['/incoming/EXAMPLE_test.tar'] = 'different bytes';
		fails(function() use ($transport,$local,$part) { $transport->send($local,$part); },'not overwritten');
		unset(\phpseclib3\Net\SFTP::$files['/incoming/EXAMPLE_test.tar']);
		\phpseclib3\Net\SFTP::$files['/incoming/EXAMPLE_test.tar.partial'] = 'corrupt';
		fails(function() use ($transport,$local,$part) { $transport->send($local,$part); },'partial upload does not match');
		foreach (['.' => './EXAMPLE_test.tar','/' => '/EXAMPLE_test.tar'] as $directory => $remote) {
			$home_values = $values; $home_values['sftp_directory'] = $directory;
			(new \MDPNPreservation\SftpTransport(new \MDPNPreservation\Config($home_values)))->send($local,$part);
			verify(isset(\phpseclib3\Net\SFTP::$files[$remote]),'Support explicit SFTP home and root targets');
		}
		chmod($credential,0644);
		fails(function() use ($values) { new \MDPNPreservation\SftpTransport(new \MDPNPreservation\Config($values)); },'private credentials_file');
		print "Preservation SFTP identity, resume and immutability checks passed.\n";
	} finally { foreach (glob($root.'/*') as $file) { unlink($file); } rmdir($root); }
}
