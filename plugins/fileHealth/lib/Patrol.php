<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;
require_once __DIR__.'/Store.php';

/** Run the unchanged native patrol once and retain execution evidence, not file-health claims. */
final class Patrol {
	public const MAX_LOG_BYTES = 1048576;
	private const HEARTBEAT_SECONDS = 5;
	private const TERMINATE_SECONDS = 5;
	private $config;
	private $store;
	private $application;
	public function __construct(Config $config, Store $store, string $applicationRoot) {
		$this->config = $config; $this->store = $store;
		$this->application = realpath($applicationRoot);
		if (!$this->application || !is_file($this->application.'/setup.php') || !is_readable($this->application.'/support/bin/caUtils')) {
			throw new Failure('A native Providence installation is required.');
		}
	}
	public function run() : array {
		if (PHP_SAPI !== 'cli' || !function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
			throw new Failure('The patrol requires PHP CLI with pcntl signal support.');
		}
		$root = $this->config->stateDirectory(true);
		if ($root === $this->application || strpos($root,$this->application.'/') === 0) { throw new Failure('Patrol state must be outside the application web root.'); }
		$scope = $this->config->scope();
		$email = (string)$this->config->get('email','');
		if ($email !== '' && (!filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x20\x7f]/',$email))) { throw new Failure('Configure one valid native report recipient.'); }
		$php = realpath((string)$this->config->get('php_binary',PHP_BINARY));
		if (!$php || !is_file($php) || !is_executable($php)) { throw new Failure('Configure an executable PHP CLI binary.'); }
		$lock = $this->store->lock(); $run = null; $process = null; $pipes = []; $log = null;
		$signal = 0; $oldSignals = []; $oldAsync = pcntl_async_signals(); $oldMask = umask(0077);
		try {
			foreach ([SIGINT,SIGTERM] as $number) {
				$oldSignals[$number] = pcntl_signal_get_handler($number);
				pcntl_signal($number,static function($caught) use (&$signal) { $signal = $caught; });
			}
			pcntl_async_signals(true);
			$id = bin2hex(random_bytes(16)); $run = $this->store->startRun($id,$email !== '');
			$report = $this->store->reportFile($id); $logPath = $this->store->logFile($id);
			$this->reserve($report,$root.'/reports',$id.'.txt');
			$log = $this->reserve($logPath,$root.'/logs',$id.'.log',true);
			$argv = [$php,$this->application.'/support/bin/caUtils','check-media-fixity',
				'--kinds='.implode(',',$scope['kinds']),'--format=text','--file=reports/'.$id.'.txt','--quiet'];
			if ($scope['versions']) { $argv[] = '--versions='.implode(',',$scope['versions']); }
			if ($email !== '') { $argv[] = '--email='.$email; }
			$environment = getenv();
			if (!is_array($environment)) { throw new Failure('The patrol process environment is unavailable.'); }
			$environment['COLLECTIVEACCESS_HOME'] = $this->application;
			if ($signal) { return $this->store->finishRun($id,'interrupted',128+$signal,'interrupted',false); }
			// Descriptor 3 retains the SAME flock in the native child if the wrapper
			// is killed abruptly. Another wrapper cannot start a duplicate patrol.
			$process = @proc_open($argv,[0 => ['file','/dev/null','r'],1 => ['pipe','w'],2 => ['pipe','w'],3 => $lock],$pipes,$root,$environment,['bypass_shell' => true]);
			if (!is_resource($process)) { throw new Failure('The native patrol could not be started.'); }
			$state = proc_get_status($process);
			$this->store->heartbeat($id,(int)$state['pid']);
			$exit = $this->monitor($process,$pipes,$log,$id,$signal,$state);
			$closed = proc_close($process); $process = null;
			if ($exit === null && $closed >= 0) { $exit = $closed; }
			$available = $this->reportAvailable($report);
			if ($signal) { return $this->store->finishRun($id,'interrupted',128+$signal,'interrupted',$available); }
			if ($exit === null || !$available) { return $this->store->finishRun($id,'failed',$exit,$available ? 'native_failed' : 'report_missing',$available); }
			return $this->store->finishRun($id,$exit === 0 ? 'no_errors_reported' : 'attention',$exit,
				$exit === 0 ? 'native_no_errors' : 'native_reported_errors',true);
		} catch (\Throwable $error) {
			if (is_resource($process)) {
				$this->stop($process);
				foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } $pipes = [];
				proc_close($process); $process = null;
			}
			if ($run) {
				return $this->store->finishRun($run['id'],$signal ? 'interrupted' : 'failed',$signal ? 128+$signal : null,
					$signal ? 'interrupted' : 'native_failed',isset($report) && $this->reportAvailable($report));
			}
			throw new Failure('The native patrol could not be journaled.');
		} finally {
			foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
			if (is_resource($log)) { fclose($log); }
			if (is_resource($process)) { proc_close($process); }
			foreach ($oldSignals as $number => $handler) { pcntl_signal($number,$handler); }
			pcntl_async_signals($oldAsync); umask($oldMask);
			Store::unlock($lock);
		}
	}
	private function reserve(string $path, string $directory, string $name, bool $keep=false) {
		if ($path !== $directory.'/'.$name || !is_dir($directory) || is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0077)) {
			throw new Failure('Invalid private patrol artifact directory.');
		}
		$file = @fopen($path,'x+b');
		if (!$file) { throw new Failure('A private patrol artifact could not be created.'); }
		if ($keep) { return $file; }
		fclose($file); return null;
	}
	private function reportAvailable(string $path) : bool {
		clearstatcache(true,$path);
		return !is_link($path) && is_file($path) && is_readable($path) && (fileperms($path) & 0077) === 0 && filesize($path) > 0;
	}
	private function monitor($process, array &$pipes, $log, string $id, int &$signal, array $state) : ?int {
		foreach ($pipes as $pipe) { stream_set_blocking($pipe,false); }
		$heartbeat = microtime(true); $stopping = null; $exit = !$state['running'] && $state['exitcode'] >= 0 ? (int)$state['exitcode'] : null;
		$ended = null; $written = 0; $truncated = false;
		while (true) {
			$now = microtime(true);
			if ($signal && $state['running']) {
				if ($stopping === null) { proc_terminate($process,$signal); $stopping = $now; }
				elseif ($now-$stopping >= self::TERMINATE_SECONDS) { proc_terminate($process,SIGKILL); }
			}
			if ($now-$heartbeat >= self::HEARTBEAT_SECONDS) { $this->store->heartbeat($id,(int)$state['pid']); $heartbeat = $now; }
			$ready = [];
			foreach ($pipes as $key => $pipe) {
				if (feof($pipe)) { fclose($pipe); unset($pipes[$key]); } else { $ready[] = $pipe; }
			}
			if ($ready) {
				$write = $except = null;
				$selected = @stream_select($ready,$write,$except,0,200000);
				if ($selected !== false && $selected > 0) {
					foreach ($ready as $pipe) {
						$data = fread($pipe,8192);
						if ($data === false) { throw new Failure('Native patrol output could not be read.'); }
						$this->appendLog($log,$data,$written,$truncated);
					}
				}
			} elseif ($state['running']) { usleep(200000); }
			$state = proc_get_status($process);
			if (!$state['running']) {
				if ($state['exitcode'] >= 0) { $exit = (int)$state['exitcode']; }
				$ended = $ended ?? microtime(true);
				if (!$pipes || microtime(true)-$ended >= 2) { break; }
			}
		}
		return $exit;
	}
	private function appendLog($log, string $data, int &$written, bool &$truncated) : void {
		if ($truncated || $data === '') { return; }
		$marker = "\n[Further native output omitted: private log size limit reached.]\n";
		$room = max(0,self::MAX_LOG_BYTES-strlen($marker)-$written);
		$chunk = substr($data,0,$room);
		if ($chunk !== '' && fwrite($log,$chunk) !== strlen($chunk)) { throw new Failure('The private patrol log could not be written.'); }
		$written += strlen($chunk);
		if (strlen($data) > $room) {
			if (fwrite($log,$marker) !== strlen($marker)) { throw new Failure('The private patrol log could not be written.'); }
			$written += strlen($marker); $truncated = true;
		}
		fflush($log);
	}
	private function stop($process) : void {
		$state = proc_get_status($process);
		if (!$state['running']) { return; }
		proc_terminate($process,SIGTERM); $until = microtime(true)+self::TERMINATE_SECONDS;
		do { usleep(100000); $state = proc_get_status($process); } while ($state['running'] && microtime(true) < $until);
		if ($state['running']) { proc_terminate($process,SIGKILL); }
	}
}
