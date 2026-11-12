<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace FileHealth;
require_once __DIR__.'/Store.php';

/** Each CLI invocation advances a sweep; a crash repeats only its uncommitted representation. */
final class Scheduler {
	private $config;
	private $store;
	private $catalogue;
	private $checker;
	public function __construct(Config $config, Store $store, $catalogue, $checker) {
		$this->config=$config; $this->store=$store; $this->catalogue=$catalogue; $this->checker=$checker;
	}
	public function run(int $userId, bool $restart=false) : array {
		if (!$this->config->enabled()) { throw new Failure('File Health is disabled.'); }
		$versions = $this->config->versions(); $size = $this->config->batchSize(); $seconds = $this->config->maxSeconds();
		$lock = $this->store->lock(); $run = null; $started = microtime(true);
		try {
			$this->store->heartbeat(); $run = $this->store->activeRun();
			if ($run && ($restart || $run['versions'] !== $versions)) { $this->store->endRun($run['id'],'superseded'); $run = null; }
			if (!$run) {
				$summary = $this->store->summary();
				if (!$restart && !$summary['sweep_overdue']) { return ['status' => 'not_due','summary' => $summary]; }
				$run = $this->store->startRun($this->catalogue->maxId(),$versions,$userId);
			}
			$ids = $this->catalogue->batch($run['cursor'],$size,$run['max_id']);
			$processed = 0;
			foreach ($ids as $id) {
				$results = [];
				foreach ($versions as $version) { $results[] = $this->checker->check((int)$id,$version); }
				$this->store->record($run['id'],(int)$id,$results); $processed++;
				if (microtime(true)-$started >= $seconds) { break; }
			}
			$current = $this->store->activeRun();
			if (!$this->catalogue->batch($current['cursor'],1,$current['max_id'])) { $this->store->endRun($run['id'],'completed'); }
			return ['status' => $this->store->activeRun() ? 'running' : 'completed','processed_representations' => $processed,'summary' => $this->store->summary()];
		} catch (\Throwable $e) {
			if ($run) { $this->store->endRun($run['id'],'failed'); }
			throw $e;
		} finally { Store::unlock($lock); }
	}
}
