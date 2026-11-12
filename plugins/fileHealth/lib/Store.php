<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;
require_once __DIR__.'/Config.php';

/** A native-run journal and read-only view of existing text reports; no file checks. */
final class Store {
	public const STATUSES=['running','no_errors_reported','attention','failed','interrupted','untracked'];
	private $config;
	private $root;
	private $readOnly;
	private $lockHandle;
	public function __construct(Config $config,bool $initialize=false,bool $readOnly=false) {
		$this->config=$config; $this->readOnly=$readOnly;
		if ($initialize && $readOnly) { throw new Failure('Read-only patrol storage cannot be initialized.'); }
		$this->root=$config->stateDirectory(!$readOnly);
		foreach (['runs','reports','logs'] as $name) { $this->directory($name,$initialize); }
		if (!is_dir($this->root.'/reports')) { throw new Failure('Native patrol reports are not available.'); }
	}
	private function writable() : void { if ($this->readOnly) { throw new Failure('Native patrol storage is read-only.'); } }
	private function ownsLock() : void {
		$this->writable();
		if (!is_resource($this->lockHandle)) { throw new Failure('The native patrol lock is required to update run history.'); }
	}
	private function directory(string $name,bool $create=false) : string {
		$path=$this->root.'/'.$name;
		if (!file_exists($path) && !is_link($path) && $create) {
			$this->writable();
			if (!mkdir($path,0700) && !is_dir($path)) { throw new Failure('Private patrol directories could not be created.'); }
		}
		if (file_exists($path) || is_link($path)) {
			if (!is_dir($path) || is_link($path) || realpath($path)!==$path || (fileperms($path)&0077)!==0) { throw new Failure('Patrol directories must be canonical private directories.'); }
		}
		return $path;
	}
	private static function id(string $id) : void { if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Failure('Invalid native patrol ID.'); } }
	private static function now() : string { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
	public function reportFile(string $id) : string { self::id($id); return $this->directory('reports').'/'.$id.'.txt'; }
	public function logFile(string $id) : string { self::id($id); return $this->directory('logs').'/'.$id.'.log'; }
	private function journalFile(string $id) : string { self::id($id); return $this->directory('runs').'/'.$id.'.json'; }
	public function lock() {
		$this->writable();
		if (is_resource($this->lockHandle)) { throw new Failure('This native patrol already holds its lock.'); }
		$path=$this->root.'/patrol.lock';
		if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new Failure('Invalid native patrol lock.'); }
		$old=umask(0077);
		try { $handle=fopen($path,'c'); } finally { umask($old); }
		if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) { fclose($handle); } throw new Failure('A native media patrol is already running.'); }
		$this->lockHandle=$handle; return $handle;
	}
	public static function unlock($lock) : void { flock($lock,LOCK_UN); fclose($lock); }
	private function locked() : bool {
		if (is_resource($this->lockHandle)) { return true; }
		$path=$this->root.'/patrol.lock';
		if (!is_file($path) || is_link($path)) { return false; }
		$handle=@fopen($path,'r');
		if (!$handle) { return true; } // Unknown lock state must not imply completion.
		$free=flock($handle,LOCK_EX|LOCK_NB);
		if ($free) { flock($handle,LOCK_UN); }
		fclose($handle); return !$free;
	}
	private function write(array $run) : void {
		$this->writable(); $path=$this->journalFile($run['id']);
		if (is_link($path)) { throw new Failure('Invalid native patrol journal.'); }
		$temp=tempnam($this->directory('runs'),'.run-');
		if ($temp===false) { throw new Failure('The native patrol journal could not be written.'); }
		try {
			$bytes=json_encode($run,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
			if (file_put_contents($temp,$bytes)!==strlen($bytes) || !chmod($temp,0600) || !rename($temp,$path)) { throw new Failure('The native patrol journal could not be written.'); }
		} finally { if (is_file($temp)) { unlink($temp); } }
	}
	private function raw(string $id) : array {
		$path=$this->journalFile($id);
		if (!is_file($path) || is_link($path) || filesize($path)>65536) { throw new Failure('The native patrol journal is unavailable.'); }
		try { $run=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR); }
		catch (\Throwable $error) { throw new Failure('The native patrol journal is unreadable.'); }
		if (!is_array($run) || ($run['format']??null)!=='native-patrol-1' || ($run['id']??null)!==$id || !in_array($run['status']??null,self::STATUSES,true)
			|| !is_array($run['scope']??null) || !is_string($run['started_at']??null) || !is_string($run['heartbeat_at']??null)) { throw new Failure('The native patrol journal is unreadable.'); }
		foreach (['started_at','heartbeat_at'] as $field) { if (strtotime($run[$field])===false) { throw new Failure('The native patrol timestamp is invalid.'); } }
		if (($run['finished_at']??null)!==null && (!is_string($run['finished_at']) || strtotime($run['finished_at'])===false)) { throw new Failure('The native patrol timestamp is invalid.'); }
		return $run;
	}
	public function startRun(string $id,bool $emailRequested) : array {
		$this->ownsLock(); self::id($id);
		if (file_exists($this->journalFile($id)) || is_link($this->journalFile($id)) || file_exists($this->reportFile($id))) { throw new Failure('Native patrol IDs cannot be reused.'); }
		foreach ($this->tracked(1000,false) as $previous) {
			if ($previous['status']==='running') { $previous=$this->raw($previous['id']); $previous['status']='interrupted'; $previous['reason']='interrupted'; $this->write($previous); }
		}
		$now=self::now();
		$run=['format'=>'native-patrol-1','id'=>$id,'status'=>'running','scope'=>$this->config->scope(),'started_at'=>$now,'heartbeat_at'=>$now,'finished_at'=>null,
			'exit_code'=>null,'reason'=>'','email_requested'=>$emailRequested,'report_available'=>false];
		$this->write($run); return $run;
	}
	public function heartbeat(string $id,?int $pid=null) : void {
		$this->ownsLock();
		$run=$this->raw($id);
		if ($run['status']!=='running') { throw new Failure('Only a running native patrol can update its heartbeat.'); }
		$run['heartbeat_at']=self::now();
		if ($pid!==null) { $run['process_id']=$pid; }
		$this->write($run);
	}
	public function finishRun(string $id,string $status,?int $exitCode,string $reason,bool $reportAvailable) : array {
		$this->ownsLock();
		$run=$this->raw($id);
		if ($run['status']!=='running' || !in_array($status,['no_errors_reported','attention','failed','interrupted'],true) || !preg_match('/^[a-z_]{1,80}$/D',$reason)) { throw new Failure('Invalid native patrol completion.'); }
		$reportAvailable=$reportAvailable && $this->reportExists($id);
		if ($status==='no_errors_reported' && ($exitCode!==0 || !$reportAvailable)) { throw new Failure('A successful native invocation requires exit zero and an available report.'); }
		if ($status==='attention' && ($exitCode===null || $exitCode===0 || !$reportAvailable)) { throw new Failure('Native attention requires a nonzero exit and an available report.'); }
		$run['status']=$status; $run['exit_code']=$exitCode; $run['reason']=$reason; $run['report_available']=$reportAvailable;
		$run['finished_at']=self::now(); $run['heartbeat_at']=$run['finished_at'];
		$this->write($run); return $run;
	}
	private function regular(string $path,string $directory) : bool {
		return is_file($path) && !is_link($path) && realpath($path)===$directory.'/'.basename($path) && is_readable($path);
	}
	private function reportExists(string $id) : bool {
		$path=$this->reportFile($id); return $this->regular($path,$this->directory('reports')) && filesize($path)>0;
	}
	private function tracked(int $limit=1000,bool $derive=true) : array {
		$directory=$this->directory('runs'); $files=is_dir($directory) ? glob($directory.'/*.json') : [];
		$rows=[];
		foreach ($files ?: [] as $path) {
			$id=basename($path,'.json'); if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { continue; }
			try { $run=$this->raw($id); }
			catch (Failure $error) {
				$run=['id'=>$id,'status'=>'failed','scope'=>null,'started_at'=>null,'finished_at'=>null,'heartbeat_at'=>null,'exit_code'=>null,'reason'=>'journal_unreadable','email_requested'=>null];
			}
			$run['report_available']=$this->reportExists($id);
			if ($derive && $run['status']==='running' && !$this->locked()) { $run['status']='interrupted'; $run['reason']='interrupted'; }
			$run['report_at']=$run['report_available'] ? gmdate('c',filemtime($this->reportFile($id))) : null;
			$run['_sort']=$run['started_at'] ?? gmdate('c',filemtime($path)); $rows[]=$run;
		}
		usort($rows,static function($a,$b) { return strcmp($b['_sort'],$a['_sort']); });
		return array_slice($rows,0,$limit);
	}
	private function legacy() : array {
		$directory=$this->directory('reports'); $rows=[];
		foreach (glob($directory.'/*.txt') ?: [] as $path) {
			if (!$this->regular($path,$directory)) { continue; }
			$name=basename($path,'.txt');
			if (preg_match('/^[a-f0-9]{32}$/D',$name) && is_file($this->journalFile($name))) { continue; }
			$time=gmdate('c',filemtime($path));
			$rows[]=['id'=>'legacy-'.hash('sha256',basename($path)),'status'=>'untracked','scope'=>null,'started_at'=>null,'finished_at'=>null,'heartbeat_at'=>null,
				'exit_code'=>null,'reason'=>'untracked_report','email_requested'=>null,'report_available'=>true,'report_at'=>$time,'_sort'=>$time];
		}
		return $rows;
	}
	public function recentRuns(int $limit=25) : array {
		$rows=array_merge($this->tracked(),$this->legacy());
		usort($rows,static function($a,$b) { return strcmp($b['_sort'],$a['_sort']); });
		$rows=array_slice($rows,0,max(1,min(200,$limit)));
		return array_map([self::class,'publicRow'],$rows);
	}
	private static function publicRow(?array $row) : ?array {
		if ($row===null) { return null; }
		return array_intersect_key($row,array_flip(['id','status','scope','started_at','finished_at','heartbeat_at','exit_code','reason','email_requested','report_available','report_at']));
	}
	public function summary(?int $now=null) : array {
		$now=$now??time(); $runs=$this->tracked(); $active=null; $completed=null;
		foreach ($runs as $run) {
			if ($active===null && $run['status']==='running') { $active=$run; }
			if ($completed===null && in_array($run['status'],['no_errors_reported','attention'],true) && !empty($run['finished_at']) && $run['scope']===$this->config->scope()) { $completed=$run; }
		}
		$latest=$runs[0]??null;
		return ['latest_run'=>self::publicRow($latest),'last_completed_run'=>self::publicRow($completed),'last_completed_at'=>$completed['finished_at']??null,'active_run'=>self::publicRow($active),
			'untracked_active'=>$active===null && $this->locked(),
			'last_worker_at'=>$latest['heartbeat_at']??null,'worker_stale'=>$active && strtotime($active['heartbeat_at'])<$now-$this->config->staleSeconds(),
			'overdue'=>!$completed || strtotime($completed['started_at'])<$now-$this->config->intervalSeconds()-$this->config->graceSeconds(),
			'scope'=>$this->config->scope(),'untracked_reports'=>count($this->legacy()),'tracked_run_count'=>count($runs)];
	}
	public function reportPath(string $id) : string {
		if (preg_match('/^[a-f0-9]{32}$/D',$id)) { $this->raw($id); if ($this->reportExists($id)) { return $this->reportFile($id); } }
		elseif (preg_match('/^legacy-[a-f0-9]{64}$/D',$id)) {
			$directory=$this->directory('reports');
			foreach (glob($directory.'/*.txt') ?: [] as $path) {
				if ($this->regular($path,$directory) && hash_equals($id,'legacy-'.hash('sha256',basename($path)))) { return $path; }
			}
		}
		throw new Failure('The native patrol report is unavailable.');
	}
	public function reportDownloadName(string $id) : string { $this->reportPath($id); return 'native-fixity-'.$id.'.txt'; }
}
