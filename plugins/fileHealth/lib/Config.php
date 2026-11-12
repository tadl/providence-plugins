<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;

final class Failure extends \RuntimeException {}

final class Config {
	private $values;
	public function __construct(array $values) { $this->values=$values; }
	public static function load(string $root) : self {
		$shared=\Configuration::load($root.'/conf/plugin.conf');
		$local=is_file($root.'/conf/plugin.local.conf') ? \Configuration::load($root.'/conf/plugin.local.conf') : null;
		$values=[];
		foreach (['enable','state_directory','kinds','versions','patrol_interval_seconds','overdue_grace_seconds','worker_stale_seconds'] as $key) {
			$value=$local ? $local->getScalar($key) : false;
			$values[$key]=$value !== false ? $value : $shared->getScalar($key);
		}
		return new self($values);
	}
	public function get(string $key,$default=null) { return $this->values[$key] ?? $default; }
	public function enabled() : bool { return (bool)$this->get('enable',false); }
	private function integer(string $key,int $default,int $minimum,int $maximum) : int {
		$value=$this->get($key,$default);
		if (!(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D',$value))) ||
			($number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum,'max_range'=>$maximum]])) === false) { throw new Failure('Invalid '.$key.' setting.'); }
		return $number;
	}
	public function intervalSeconds() : int { return $this->integer('patrol_interval_seconds',604800,60,31536000); }
	public function graceSeconds() : int { return $this->integer('overdue_grace_seconds',86400,0,31536000); }
	public function staleSeconds() : int { return $this->integer('worker_stale_seconds',300,10,86400); }
	public function kinds() : array {
		$value=$this->get('kinds','ca_object_representations');
		if (!is_string($value)) { throw new Failure('kinds must be a comma-separated list.'); }
		$kinds=array_values(array_unique(array_map('trim',explode(',',$value))));
		foreach ($kinds as $kind) { if (!in_array($kind,['all','ca_object_representations','ca_attributes'],true)) { throw new Failure('Invalid native patrol kind.'); } }
		if (in_array('all',$kinds,true) && count($kinds)>1) { throw new Failure('Use all alone for native patrol kinds.'); }
		return $kinds;
	}
	public function versions() : array {
		$value=$this->get('versions','original');
		if (!is_string($value)) { throw new Failure('versions must be a comma-separated list.'); }
		if (trim($value)==='') { return []; }
		$versions=array_values(array_unique(array_map('trim',explode(',',$value))));
		if (count($versions)>20) { throw new Failure('Configure no more than twenty media versions.'); }
		foreach ($versions as $version) { if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$version)) { throw new Failure('Invalid media version name.'); } }
		return $versions;
	}
	public function scope() : array { return ['kinds'=>$this->kinds(),'versions'=>$this->versions()]; }
	public function stateDirectory(bool $writable=false) : string {
		$path=rtrim((string)$this->get('state_directory','/var/lib/providence-fixity'),'/');
		if ($path==='' || $path[0]!=='/' || !is_dir($path) || is_link($path) || realpath($path)!==$path) { throw new Failure('Configure an existing canonical absolute private state_directory.'); }
		$roots=[dirname(__DIR__)];
		if (defined('__CA_BASE_DIR__')) { $roots[]=realpath(__CA_BASE_DIR__); }
		for ($p=dirname(__DIR__); dirname($p)!==$p; $p=dirname($p)) { if (file_exists($p.'/.git')) { $roots[]=$p; break; } }
		foreach ($roots as $root) { if ($root && ($path===$root || strpos($path,$root.'/')===0)) { throw new Failure('state_directory must be outside the web root and source checkout.'); } }
		if ((fileperms($path)&0077)!==0 || !is_readable($path) || ($writable && !is_writable($path))) { throw new Failure('state_directory must be private (0700) and accessible to the service account.'); }
		return $path;
	}
}
