<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Service.php';

/** Read-only, destination-specific maintenance report; never reuses stale scans. */
final class Report {
	private $config;
	private $store;
	private $root;
	private $catalogueFactory;
	public function __construct(Config $config, Store $store, string $root, ?callable $catalogueFactory=null) {
		$this->config = $config; $this->store = $store; $this->root = $root;
		$this->catalogueFactory = $catalogueFactory ?: static function($user) { return new ProvidenceCatalogue($user); };
	}
	public function run($user, bool $historyOnly=false) : array {
		if ((defined('__CA_DISABLE_ACL__') && __CA_DISABLE_ACL__) || !$user || !$user->getPrimaryKey() || !$user->get('active')
			|| !$user->canDoAction('is_administrator') || !$user->canDoAction('can_preserve_collections')) {
			throw new Failure('The all-collection report requires an active backend administrator with preservation permission and native ACL checks enabled.');
		}
		$started = gmdate('c'); $collections = $this->collections($user); $attempts = []; $latest = []; $confirmed = [];
		foreach ($this->store->reportJobs($this->config->target()) as $job) {
			$id = (int)$job['collection_id'];
			if (!isset($attempts[$id])) { $attempts[$id] = $job; }
			if (!isset($latest[$id]) && in_array($job['status'],['built','uploaded','confirmed'],true)) { $latest[$id] = $job; }
			if (!isset($confirmed[$id]) && $job['status'] === 'confirmed') { $confirmed[$id] = $job; }
		}
		$candidates = []; $warnings = []; $unknownCoverage = false;
		foreach (array_unique(array_merge(array_keys($latest),array_keys($confirmed))) as $root) {
			$jobs = [];
			foreach ([$latest[$root] ?? null,$confirmed[$root] ?? null] as $job) { if ($job) { $jobs[$job['id']] = $job; } }
			$baselines = [];
			foreach ($jobs as $job) {
				$candidate = ['job' => $job,'coverage' => [$root],'freshness' => 'not_checked','changes' => null,'checked_at' => null,'error' => null];
				try {
					$path = $this->store->directory($job['id']).'/result.json';
					if (!is_file($path) || is_link($path)) { throw new Failure('The saved preservation snapshot is missing.'); }
					$result = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR); $snapshot = $result['snapshot'] ?? [];
					if (($snapshot['repository_id'] ?? null) !== $this->store->repositoryId() || (int)($snapshot['collection_id'] ?? 0) !== $root
						|| ($snapshot['deposit_id'] ?? null) !== $job['id'] || !isset($snapshot['records']['ca_collections'][(string)$root])) {
						throw new Failure('The saved preservation snapshot has inconsistent collection identifiers.');
					}
					$candidate['coverage'] = array_map('intval',array_keys($snapshot['records']['ca_collections']));
					unset($snapshot,$result); $baselines[$job['id']] = $path;
				} catch (\Throwable $e) {
					$candidate['freshness'] = 'error'; $candidate['error'] = self::error($e);
					// A moved child may once have been covered: current ancestry cannot
					// reconstruct missing historical membership from a damaged snapshot.
					$unknownCoverage = true;
					$warnings[] = ['root_collection_id' => $root,'job_id' => $job['id'],'message' => 'Historical child coverage could not be read. Collections without other evidence need review.'];
				}
				$candidates[$job['id']] = $candidate;
			}
			if (!$historyOnly && $baselines) {
				try {
					if (!isset($collections[$root])) { throw new Failure('The deposit root is deleted or missing from the current catalogue.'); }
					Service::authorize($user,$root);
					$before = $this->capture($user,$root);
					$comparison = $this->compare($before,$baselines);
					$after = $this->capture($user,$root);
					unset($before['captured_at'],$after['captured_at']);
					if (Store::json($before) !== Store::json($after)) { throw new Failure('The collection changed during the maintenance check. Run the report again.'); }
					foreach ($comparison['comparisons'] as $jobId => $checked) {
						$candidates[$jobId]['freshness'] = $checked['current'] ? 'current' : 'out_of_date';
						$candidates[$jobId]['changes'] = $checked['changes']; $candidates[$jobId]['checked_at'] = $comparison['checked_at'];
					}
				} catch (\Throwable $e) {
					foreach (array_keys($baselines) as $jobId) { $candidates[$jobId]['freshness'] = 'error'; $candidates[$jobId]['error'] = self::error($e); }
				}
			}
			if (!isset($collections[$root])) { $warnings[] = ['root_collection_id' => $root,'message' => 'A preservation deposit has a deleted or missing root. Historical child coverage requires review.']; }
		}
		$rows = []; $states = []; $attention = 0; $errors = 0;
		foreach ($collections as $id => $collection) {
			$deposits = []; $preserved = [];
			foreach ($candidates as $jobId => $candidate) {
				if (!in_array($id,$candidate['coverage'],true)) { continue; }
				$root = (int)$candidate['job']['collection_id'];
				if (isset($latest[$root]) && $latest[$root]['id'] === $jobId) { $deposits[] = $candidate; }
				if (isset($confirmed[$root]) && $confirmed[$root]['id'] === $jobId) { $preserved[] = $candidate; }
			}
			$deposit = $this->best($deposits,$id); $preservation = $this->best($preserved,$id);
			$status = 'never_deposited';
			if (($preservation['freshness'] ?? null) === 'error' || ($deposit['freshness'] ?? null) === 'error') { $status = 'error'; }
			elseif (($preservation['freshness'] ?? null) === 'current') { $status = 'current'; }
			elseif (($deposit['freshness'] ?? null) === 'current') { $status = $deposit['delivery_status'] === 'built' ? 'local_only' : 'awaiting_confirmation'; }
			elseif (($preservation['freshness'] ?? null) === 'out_of_date' || ($deposit['freshness'] ?? null) === 'out_of_date') { $status = 'out_of_date'; }
			elseif ($deposit || $preservation) { $status = $historyOnly ? 'not_checked' : 'needs_review'; }
			$coverageIncomplete = $unknownCoverage && !$deposit && !$preservation;
			if ($status === 'never_deposited' && $coverageIncomplete) { $status = 'needs_review'; }
			$attempt = null;
			foreach ($this->ancestors($collections,$id) as $ancestor) {
				if (isset($attempts[$ancestor]) && (!$attempt || strcmp($attempts[$ancestor]['created_at'],$attempt['created_at']) > 0)) { $attempt = $attempts[$ancestor]; }
			}
			$requiresAttention = $status !== 'current' || ($attempt && $attempt['status'] === 'failed');
			$row = ['collection_id' => $id,'parent_id' => $collection['parent_id'],'label' => $collection['label'],'status' => $status,
				'attention_required' => (bool)$requiresAttention,'coverage_incomplete' => $coverageIncomplete,'preservation' => $preservation,'latest_deposit' => $deposit,
				'latest_attempt' => $attempt ? ['job_id' => $attempt['id'],'root_collection_id' => (int)$attempt['collection_id'],'status' => $attempt['status'],'created_at' => $attempt['created_at']] : null];
			$rows[] = $row; $states[$status] = ($states[$status] ?? 0)+1;
			if ($requiresAttention) { $attention++; } if ($status === 'error') { $errors++; }
		}
		ksort($states);
		return ['format' => 'mdpn-ca-preservation-report-1','started_at' => $started,'finished_at' => gmdate('c'),
			'destination' => ['transport' => $this->config->get('transport','local'),'id' => $this->config->get('destination_id')],
			'freshness_check' => $historyOnly ? 'history_only' : 'metadata_and_sha256','summary' => ['collections' => count($rows),'attention_required' => $attention,
				'errors' => $errors,'states' => $states],'warnings' => $warnings,'collections' => $rows];
	}
	private function collections($user) : array {
		$c = ($this->catalogueFactory)($user); $c->begin();
		try {
			$rows = $c->rows('ca_collections'); $labels = []; $info = $c->info('ca_collections');
			if ($info['label_table']) {
				foreach ($c->rows($info['label_table'],'collection_id',array_keys($rows)) as $label) {
					if (!isset($labels[$label['collection_id']]) || !empty($label['is_preferred'])) { $labels[$label['collection_id']] = (string)($label['name'] ?? ''); }
				}
			}
			$result = [];
			foreach ($rows as $id => $row) { if (empty($row['deleted'])) { $result[(int)$id] = ['parent_id' => (int)($row['parent_id'] ?? 0),'label' => $labels[$id] ?? 'Collection #'.$id]; } }
			ksort($result,SORT_NUMERIC); return $result;
		} finally { $c->finish(); }
	}
	private function capture($user, int $root) : array {
		$snapshot = (new Collector(($this->catalogueFactory)($user)))->capture($root,$this->store->repositoryId());
		$snapshot['schema']['bagit_profile'] = $this->config->profile(); return $snapshot;
	}
	private function compare(array $snapshot, array $baselines) : array {
		$binary = (string)$this->config->get('python_binary','/usr/bin/python3');
		if (!$binary || $binary[0] !== '/' || !is_executable($binary)) { throw new Failure('Configure an absolute executable python_binary (Python 3.9 or newer).'); }
		$process = proc_open([$binary,'-B',$this->root.'/worker/preservation.py','compare'],[0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes,null,['PATH' => '/usr/bin:/bin','PYTHONNOUSERSITE' => '1']);
		if (!is_resource($process)) { throw new Failure('The preservation comparison worker could not start.'); }
		try {
			$request = Store::json(['snapshot' => $snapshot,'baselines' => $baselines]); $offset = 0;
			while ($offset < strlen($request)) {
				$written = @fwrite($pipes[0],substr($request,$offset,1048576));
				if (!$written) { break; } $offset += $written;
			}
			fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
		} finally { foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } $exit = proc_close($process); }
		if ($exit !== 0 || $offset !== strlen($request)) { throw new Failure(trim(substr($error,0,1000)) ?: 'The preservation comparison worker failed.'); }
		return json_decode($output,true,512,JSON_THROW_ON_ERROR);
	}
	private function best(array $candidates, int $collection) : ?array {
		if (!$candidates) { return null; }
		foreach ($candidates as &$candidate) {
			$candidate['inherited'] = (int)$candidate['job']['collection_id'] !== $collection;
			if ($candidate['inherited'] && $candidate['freshness'] === 'out_of_date') { $candidate['freshness'] = 'needs_review'; }
		} unset($candidate);
		$rank = ['current' => 0,'error' => 1,'out_of_date' => 2,'needs_review' => 3,'not_checked' => 4];
		usort($candidates,static function($a,$b) use ($rank) {
			$order = [$rank[$a['freshness']],(int)$a['inherited']] <=> [$rank[$b['freshness']],(int)$b['inherited']];
			return $order ?: strcmp($b['job']['created_at'],$a['job']['created_at']);
		});
		$c = $candidates[0]; $job = $c['job'];
		return ['job_id' => $job['id'],'root_collection_id' => (int)$job['collection_id'],'inherited' => $c['inherited'],
			'freshness' => $c['freshness'],'checked_at' => $c['checked_at'],'delivery_status' => $job['status'],'captured_at' => $job['result']['captured_at'] ?? null,
			'confirmed_at' => $job['confirmation']['recorded_at'] ?? null,'revision' => (int)$job['revision'],'mode' => $job['mode'],
			'parts' => count($job['result']['parts'] ?? []),'supplements' => max(0,count($job['result']['recovery_chain'] ?? [])-1),
			'changes_in_deposit_root' => $c['changes'],'error' => $c['error']];
	}
	private function ancestors(array $collections, int $id) : array {
		$seen = [];
		while (isset($collections[$id]) && !isset($seen[$id])) { $seen[$id] = $id; $id = $collections[$id]['parent_id']; }
		return array_values($seen);
	}
	private static function error(\Throwable $e) : string { return $e instanceof Failure ? $e->getMessage() : 'The preservation snapshot or catalogue could not be checked. Review private storage and catalogue configuration.'; }
	public static function render(array $report, string $format) : string {
		if ($format === 'json') { return Store::json($report)."\n"; }
		if ($format !== 'text') { throw new Failure('Report format must be text or json.'); }
		$out = 'Preservation status: '.$report['destination']['transport'].':'.$report['destination']['id'].' ('.$report['freshness_check'].")\n";
		$out .= sprintf("%-8s %-23s %-17s %-17s %-10s %-8s %s\n",'ID','STATUS','PRESERVATION','LATEST DEPOSIT','SNAPSHOT','VIA ROOT','COLLECTION');
		foreach ($report['collections'] as $row) {
			$coverage = $row['preservation'] ?: $row['latest_deposit'];
			$label = preg_replace('/[\x00-\x1f\x7f]+/',' ',$row['label']);
			$out .= sprintf("%-8d %-23s %-17s %-17s %-10s %-8s %s\n",$row['collection_id'],$row['status'],$row['preservation']['freshness'] ?? 'never_confirmed',
				$row['latest_deposit']['freshness'] ?? 'none',substr($coverage['captured_at'] ?? '-',0,10),$coverage['root_collection_id'] ?? '-', $label);
			if (($row['latest_attempt']['status'] ?? '') === 'failed') { $out .= "         Latest deposit attempt failed.\n"; }
			foreach (array_unique(array_filter([$row['preservation']['error'] ?? null,$row['latest_deposit']['error'] ?? null])) as $error) { $out .= '         '.$error."\n"; }
		}
		$out .= 'Collections: '.$report['summary']['collections'].'; attention required: '.$report['summary']['attention_required'].'; errors: '.$report['summary']['errors']."\n";
		foreach ($report['warnings'] as $warning) { $out .= 'Warning for root #'.$warning['root_collection_id'].': '.$warning['message']."\n"; }
		return $out;
	}
}
