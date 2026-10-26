<?php
require_once __DIR__.'/CollectionAclSynchronizer.php';

class CollectionAclTool {
	private $scope;
	public function __construct(CollectionAccessScope $scope) { $this->scope = $scope; }

	public function run($command, $settings) : bool {
		try {
			$apply = !empty($settings['apply']);
			if ($apply && !empty($settings['dry-run'])) { throw new RuntimeException('Choose either --apply or --dry-run.'); }
			$sync = new CollectionAclSynchronizer($this->scope);
			if (!empty($settings['config'])) {
				foreach ($sync::requiredSettings() as $key => $value) { print $key.' = '.$value."\n"; }
				return true;
			}
			$plan = $sync->inspect();
			$summary = ['mode' => $apply ? 'apply' : 'dry-run', 'changes' => $plan['summary'],
				'configuration_errors' => $sync->configurationErrors(), 'conflicts' => array_values(array_unique($plan['collisions']))];
			print json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
			if ($plan['collisions']) { return false; }
			if ($apply) { $sync->apply($plan, $settings['backup-dir'] ?? null); }
			return true;
		} catch (Throwable $error) {
			fwrite(STDERR, $error->getMessage()."\n");
			return false;
		}
	}
}
