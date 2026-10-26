<?php
/** Native ACL projection for collections/objects; shared authorities are absent. */
class CollectionAclPolicy {
	const OWNER = 'groupCollectionRootMap:acl:v1';
	const ALL_GROUP = 'gcrm_all';
	const TABLES = ['ca_collections', 'ca_objects'];

	public static function rootGroup(int $id) : string { return 'gcrm_r_'.$id; }

	/** Inputs contain IDs/parent IDs only, with deleted records already excluded. */
	public static function project(array $collections, array $objects, array $links, array $scopes) : array {
		$groups = [self::ALL_GROUP => null];
		$memberships = [];
		foreach ($scopes as $user_id => $scope) {
			$codes = [];
			if (empty($scope['restricted'])) {
				$codes[] = self::ALL_GROUP;
			} else {
				foreach ($scope['allowed_root_ids'] as $root) {
					$root = (int)$root;
					if (!array_key_exists($root, $collections)) { throw new RuntimeException('A mapped collection root is missing or deleted.'); }
					$groups[$code = self::rootGroup($root)] = $root;
					$codes[] = $code;
				}
			}
			$memberships[(int)$user_id] = array_values(array_unique($codes));
		}

		$collection_groups = [];
		$visiting = [];
		$walk = function(int $id) use (&$walk, &$collection_groups, &$visiting, $collections, $groups) {
			if (isset($collection_groups[$id])) { return $collection_groups[$id]; }
			if (isset($visiting[$id])) { throw new RuntimeException('Cycle in the collection hierarchy.'); }
			$visiting[$id] = true;
			$parent = (int)$collections[$id];
			$codes = ($parent && array_key_exists($parent, $collections)) ? $walk($parent) : [self::ALL_GROUP];
			if (array_key_exists($code = self::rootGroup($id), $groups)) { $codes[] = $code; }
			unset($visiting[$id]);
			return $collection_groups[$id] = array_values(array_unique($codes));
		};
		foreach ($collections as $id => $parent) { $walk((int)$id); }

		$direct = [];
		foreach ($links as $link) {
			[$object_id, $collection_id] = $link;
			if (isset($collection_groups[$collection_id]) && array_key_exists($object_id, $objects)) {
				$direct[$object_id] = array_merge($direct[$object_id] ?? [], $collection_groups[$collection_id]);
			}
		}
		$object_groups = [];
		$visiting = [];
		$walk_object = function(int $id) use (&$walk_object, &$object_groups, &$visiting, $objects, $direct) {
			if (isset($object_groups[$id])) { return $object_groups[$id]; }
			if (isset($visiting[$id])) { throw new RuntimeException('Cycle in the object hierarchy.'); }
			$visiting[$id] = true;
			$parent = (int)$objects[$id];
			$codes = ($parent && array_key_exists($parent, $objects)) ? $walk_object($parent) : [self::ALL_GROUP];
			$codes = array_merge($codes, $direct[$id] ?? []);
			unset($visiting[$id]);
			return $object_groups[$id] = array_values(array_unique($codes));
		};
		foreach ($objects as $id => $parent) { $walk_object((int)$id); }
		return ['groups' => $groups, 'memberships' => $memberships, 'grants' => [
			'ca_collections' => $collection_groups, 'ca_objects' => $object_groups
		]];
	}

	public static function grantKeys(array $projection) : array {
		$keys = [];
		foreach ($projection['grants'] as $table => $records) {
			foreach ($records as $id => $codes) {
				foreach ($codes as $code) { $keys[$table.':'.$id.':'.$code] = true; }
			}
		}
		ksort($keys);
		return $keys;
	}
}
