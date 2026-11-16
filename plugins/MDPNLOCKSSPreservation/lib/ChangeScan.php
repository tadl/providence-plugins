<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Service.php';

/** Scheduled change detection, distinct from original-file fixity verification. */
final class ChangeScan {
	public const METHOD = 'change_log_and_file_metadata';
	private const MEANING = [
		'ca_locales' => ['locale_id','name','language','country','dialect'],
		'ca_metadata_elements' => ['element_id','element_code','datatype','list_id','parent_id','deleted'],
		'ca_metadata_element_labels' => ['element_id','locale_id','name','description','is_preferred'],
		'ca_lists' => ['list_id','list_code','is_hierarchical','deleted'], 'ca_list_labels' => ['list_id','locale_id','name'],
		'ca_list_items' => ['item_id','list_id','parent_id','idno','item_value','deleted'],
		'ca_list_item_labels' => ['item_id','locale_id','name_singular','name_plural','description','is_preferred','sdatetime','edatetime'],
		'ca_relationship_types' => ['type_id','table_num','type_code','parent_id','sub_type_left_id','sub_type_right_id'],
		'ca_relationship_type_labels' => ['type_id','locale_id','typename','typename_reverse','description','description_reverse']
	];
	private $config;
	private $store;
	private $root;
	private $factory;
	public function __construct(Config $config, Store $store, string $root, ?callable $factory=null) {
		$this->config = $config; $this->store = $store; $this->root = $root;
		$this->factory = $factory ?: static function($user) { return new ProvidenceCatalogue($user); };
	}
	public function run($user, ?int $only=null) : array {
		if ((defined('__CA_DISABLE_ACL__') && __CA_DISABLE_ACL__) || !$user || !$user->getPrimaryKey() || !$user->get('active')
			|| !$user->canDoAction('is_administrator') || !$user->canDoAction('can_preserve_collections')) { throw new Failure('Change scanning requires an active backend administrator with native ACL checks enabled.'); }
		$c = ($this->factory)($user); $eligibility = Eligibility::load($this->config,$c); $c->begin();
		try { $hierarchy = $c->collectionHierarchy($this->config->organizationalUnitElement(),$this->config->organizationalUnitMarker()); }
		finally { $c->finish(); }
		if ($only !== null) { $eligibility->requireRoot($only); }
		$jobs = []; $active = [];
		foreach ($this->store->reportJobs($this->config->target()) as $job) {
			$id = (int)$job['collection_id'];
			if (in_array($job['status'],['queued','running'],true)) { $active[$id] = true; }
			if (in_array($job['status'],['built','uploaded','confirmed'],true) && empty($jobs[$id]['latest'])) { $jobs[$id]['latest'] = $job; }
			if ($job['status'] === 'confirmed' && empty($jobs[$id]['confirmed'])) { $jobs[$id]['confirmed'] = $job; }
		}
		$rows = []; $errors = 0; $skipped = 0;
		foreach ($hierarchy as $id => $row) {
			$id = (int)$id;
			if (!empty($row['deleted']) || ($only !== null && $only !== $id) || !$eligibility->classify($id)['eligible']) { continue; }
			if (isset($active[$id])) { $skipped++; continue; }
			$lock = null; $checked = gmdate('c');
			try {
				$lock = $this->store->lock($id); Service::authorize($user,$id);
				$baselines = []; foreach ($jobs[$id] ?? [] as $job) { $baselines[$job['id']] = $job; }
				$summary = ['format' => 1,'collection_id' => $id,'checked_at' => $checked,'method' => self::METHOD,
					'latest_deposit_id' => $jobs[$id]['latest']['id'] ?? null,'checks' => [],'error' => null];
				$caches = [];
				if ($baselines) {
					$c = ($this->factory)($user); $c->begin();
					try {
						$scope = self::scope($c,$id);
						foreach ($baselines as $jobId => $job) {
							$cache = $this->store->scanBaseline($jobId);
							if (!$cache || ($cache['fingerprint'] ?? null) !== $job['result']['fingerprint']) { $cache = $this->inventory($job); }
							$watch = $scope;
							foreach ($cache['scope'] as $table => $ids) {
								// Preview records are omitted by the inventory extractor.
								$watch[$table] = array_values(array_unique(array_merge($watch[$table] ?? [],$ids)));
							}
							$events = array_values(array_filter($c->scanEvents($watch,(int)strtotime($job['result']['captured_at']),$cache['change_log_cursor'] ?? null),static function($event) use ($id) { return self::significant($event,$id); }));
							$metadataChanges = $events ? $this->changedRows($c,$id,$cache,$events) : 0;
							$fileChanges = $this->files($c,$cache,$job);
							$structureChanges = 0;
							foreach (['ca_collections','ca_objects','ca_objects_x_collections','ca_objects_x_object_representations'] as $table) {
								$structureChanges += count(array_diff($cache['scope'][$table] ?? [],$scope[$table] ?? [])) + count(array_diff($scope[$table] ?? [],$cache['scope'][$table] ?? []));
							}
							$counts = ['catalogue_changes' => $metadataChanges,'file_metadata_changes' => $fileChanges,'structure_changes' => $structureChanges];
							$summary['checks'][$jobId] = ['fingerprint' => $job['result']['fingerprint'],'freshness' => $metadataChanges || $fileChanges || $structureChanges ? 'out_of_date' : 'current',
								'checked_at' => $checked,'method' => self::METHOD,'changes' => $counts,'error' => null];
							$caches[$jobId] = $cache;
						}
					} finally { $c->finish(); }
				}
				$this->store->saveChangeScan($id,$this->config->target(),$summary,$caches); $rows[] = $summary;
			} catch (\Throwable $e) {
				if (!$lock) { $skipped++; continue; }
				$errors++;
				$summary = ['format' => 1,'collection_id' => $id,'checked_at' => $checked,'method' => self::METHOD,
					'latest_deposit_id' => $jobs[$id]['latest']['id'] ?? null,'checks' => [],
					'error' => $e instanceof Failure ? $e->getMessage() : 'Change scanning failed. Check the private catalogue and preservation configuration.'];
				$this->store->saveChangeScan($id,$this->config->target(),$summary); $rows[] = $summary;
			} finally { if ($lock) { flock($lock,LOCK_UN); fclose($lock); } }
		}
		return ['format' => 'mdpn-ca-change-scan-1','finished_at' => gmdate('c'),'method' => self::METHOD,
			'summary' => ['checked' => count($rows),'skipped_active' => $skipped,'errors' => $errors],'collections' => $rows];
	}
	private function inventory(array $job) : array {
		$path = $this->store->directory($job['id']).'/result.json';
		if (!is_file($path) || is_link($path)) { throw new Failure('The deposit recovery metadata needed to initialize this scan is missing.'); }
		$request = ['path' => $path,'expected' => ['repository_id' => $this->store->repositoryId(),'collection_id' => (int)$job['collection_id'],
			'deposit_id' => $job['id'],'fingerprint' => $job['result']['fingerprint']]];
		return $this->worker('scan-inventory',$request);
	}
	private function changedRows($c, int $root, array $cache, array $events) : int {
		$ids = []; $rows = [];
		foreach ($events as $event) { $ids[$event['table']][] = $event['row_id']; }
		foreach ($ids as $table => $keys) { $rows[$table] = $c->rows($table,$c->info($table)['primary_key'],$keys); }
		$result = $this->worker('scan-rows',['collection_id' => $root,'hashes' => $cache['row_hashes'],'events' => $events,'rows' => Collector::portable($rows)]);
		return $result['changed'];
	}
	private function worker(string $command, array $request) : array {
		$binary = (string)$this->config->get('python_binary','/usr/bin/python3');
		if (!$binary || $binary[0] !== '/' || !is_executable($binary)) { throw new Failure('Configure an absolute executable python_binary for metadata comparison.'); }
		$process = proc_open([$binary,'-B',$this->root.'/worker/preservation.py',$command],[0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes,null,['PATH' => '/usr/bin:/bin','PYTHONNOUSERSITE' => '1']);
		if (!is_resource($process)) { throw new Failure('The scan inventory worker could not start.'); }
		try {
			$data = Store::json($request); $offset = 0;
			while ($offset < strlen($data)) { $written = fwrite($pipes[0],substr($data,$offset,1048576)); if (!$written) { throw new Failure('The scan comparison request could not be written.'); } $offset += $written; }
			fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
		}
		finally { foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } $exit = proc_close($process); }
		if ($exit !== 0) { throw new Failure(trim(substr($error,0,1000)) ?: 'The scan inventory could not be initialized.'); }
		return json_decode($output,true,512,JSON_THROW_ON_ERROR);
	}
	private function files($c, array &$cache, array $job) : int {
		$changed = 0;
		if (!isset($cache['sources'])) { $cache['sources'] = Collector::portable($c->scanFileSpecs($cache['files'])); }
		foreach ($cache['files'] as $file) {
			$key = $file['key']; $source = $cache['sources'][$key] ?? null;
			if (!$source) { $changed++; continue; }
			$path = $source['source_path']; if (is_array($path)) { $path = base64_decode($path['$base64'],true); } clearstatcache(true,$path);
			$stat = !is_link($path) ? @stat($path) : false;
			if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || !is_readable($path)) { throw new Failure('A preserved original is missing or unreadable.'); }
			$observed = array_intersect_key($stat,array_fill_keys(['dev','ino','size','mtime','ctime'],true));
			if (isset($cache['observations'][$key])) {
				if ($cache['observations'][$key] !== $observed) { $cache['changed_files'][$key] = true; }
			} else {
				if ($observed['size'] !== $file['size'] || $observed['mtime'] > (int)strtotime($job['result']['captured_at'])
					|| $source['original_filename'] !== $file['original_filename']) { $cache['changed_files'][$key] = true; }
				$cache['observations'][$key] = $observed;
			}
			if (!empty($cache['changed_files'][$key])) { $changed++; }
		}
		return $changed;
	}
	public static function significant(array $event, int $root) : bool {
		if ($event['operation'] !== 'U' || !is_array($event['fields'])) { return true; }
		if (isset(self::MEANING[$event['table']])) { return (bool)array_intersect($event['fields'],self::MEANING[$event['table']]); }
		$ignored = ['hier_left','hier_right','view_count'];
		if ($event['table'] === 'ca_collections' && (int)$event['row_id'] === $root) { $ignored[] = 'rank'; }
		return (bool)array_diff($event['fields'],$ignored);
	}
	/** Small projections avoid loading media blobs, profiles or authority descriptions. */
	public static function scope($c, int $root) : array {
		$collector = new Collector($c);
		$fields = static function($table) use ($c) { return array_values(array_intersect($c->info($table)['fields'],[$c->info($table)['primary_key'],'parent_id','deleted','type_id','locale_id'])); };
		$collections = $collector->descendants('ca_collections',[$root],$fields('ca_collections'));
		if (!isset($collections[$root])) { throw new Failure('The collection is missing or deleted.'); }
		$members = $c->rows('ca_objects_x_collections','collection_id',array_keys($collections)); $type = $c->hierarchyType(); $ids = [];
		foreach ($members as $member) { if ($type === null || (int)$member['type_id'] === $type) { $ids[] = (int)$member['object_id']; } }
		$objects = $collector->descendants('ca_objects',$ids,$fields('ca_objects'));
		$links = $c->rows('ca_objects_x_object_representations','object_id',array_keys($objects));
		$representations = $c->rows('ca_object_representations','representation_id',array_column($links,'representation_id'),$fields('ca_object_representations'));
		$scope = ['ca_collections' => array_keys($collections),'ca_objects' => array_keys($objects),'ca_object_representations' => array_keys($representations)];
		$records = ['ca_collections' => $collections,'ca_objects' => $objects,'ca_object_representations' => $representations];
		foreach ($c->relationshipTables() as $table) {
			$r = $c->info($table)['relationship']; $rows = [];
			foreach (['left','right'] as $side) { if (isset($scope[$r[$side.'_table']])) { $rows += $c->rows($table,$r[$side.'_field'],$scope[$r[$side.'_table']]); } }
			if ($rows) { $scope[$table] = array_keys($rows); $records[$table] = $rows; }
		}
		$pending = []; $seen = [];
		$elements = in_array('ca_metadata_elements',$c->tables(),true) ? $c->rows('ca_metadata_elements',null,[],['element_id','datatype']) : [];
		$add = static function($table,$id) use (&$pending,$c) { if ((int)$id > 0 && isset(self::MEANING[$table]) && in_array($table,$c->tables(),true)) { $pending[] = [$table,(int)$id]; } };
		$references = static function($table,$row) use ($c,$add) {
			foreach ($c->info($table)['foreign_keys'] as $r) { $add($r['one_table'],$row[$r['many_table_field']] ?? 0); }
			foreach (['element_id' => 'ca_metadata_elements','locale_id' => 'ca_locales','list_id' => 'ca_lists','item_id' => 'ca_list_items'] as $field => $target) { $add($target,$row[$field] ?? 0); }
			if (isset(self::MEANING[$table])) { $add($table,$row['parent_id'] ?? 0); }
		};
		foreach ($records as $table => $rows) {
			foreach ($rows as $row) { $references($table,$row); }
			$info = $c->info($table); $attributeIds = [];
			if ($info['label_table']) {
				$labelInfo = $c->info($info['label_table']);
				foreach ($c->rows($info['label_table'],$info['primary_key'],array_keys($rows),array_values(array_intersect($labelInfo['fields'],[$labelInfo['primary_key'],'locale_id']))) as $label) { $references($info['label_table'],$label); }
			}
			foreach ($c->rows('ca_attributes','row_id',array_keys($rows),array_values(array_intersect($c->info('ca_attributes')['fields'],['attribute_id','row_id','table_num','element_id']))) as $attribute) {
				if ((int)$attribute['table_num'] !== (int)$info['table_num']) { continue; }
				$references('ca_attributes',$attribute); $attributeIds[] = (int)$attribute['attribute_id'];
			}
			foreach ($c->rows('ca_attribute_values','attribute_id',$attributeIds,array_values(array_intersect($c->info('ca_attribute_values')['fields'],['value_id','attribute_id','element_id','value_integer1']))) as $value) {
				$references('ca_attribute_values',$value);
				if ((int)($elements[$value['element_id']]['datatype'] ?? 0) === 3) { $add('ca_list_items',$value['value_integer1'] ?? 0); }
			}
		}
		while ($pending) {
			[$table,$id] = array_pop($pending); if (isset($seen[$table][$id])) { continue; } $seen[$table][$id] = true;
			$scope[$table][] = $id; $info = $c->info($table);
			foreach ($c->rows($table,$info['primary_key'],[$id]) as $row) { $references($table,$row); }
			if ($info['label_table']) {
				foreach ($c->rows($info['label_table'],$info['primary_key'],[$id]) as $label) { $references($info['label_table'],$label); }
			}
		}
		return $scope;
	}
}
