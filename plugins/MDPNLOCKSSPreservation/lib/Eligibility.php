<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace MDPNPreservation;
require_once __DIR__.'/Catalogue.php';

/** The first unmarked collection in an organizational branch is the deposit root. */
final class Eligibility {
	private $rows;
	private $resolved = [];
	private $requireOrganizationalParent;
	public function __construct(array $rows, bool $requireOrganizationalParent=false) { $this->rows = $rows; $this->requireOrganizationalParent = $requireOrganizationalParent; }
	public static function load(Config $config, $catalogue, ?int $collection=null) : self {
		$catalogue->begin();
		try { return new self($catalogue->collectionHierarchy($config->organizationalUnitElement(),$config->organizationalUnitMarker(),$collection),$config->organizationalUnitElement() !== ''); }
		finally { $catalogue->finish(); }
	}
	/** Editor affordances use native models; authoritative checks use a snapshot. */
	public static function forModel(Config $config, $model) : self {
		$rows = []; $element = $config->organizationalUnitElement(); $marker = $config->organizationalUnitMarker();
		while ($id = (int)$model->getPrimaryKey()) {
			if (isset($rows[$id])) { throw new Failure('A cycle prevents determining the preservation root.'); }
			if ($model->get('deleted')) { throw new Failure('The collection hierarchy contains a deleted record.'); }
			$marked = false;
			if ($element !== '') {
				// Owned attributes avoid inheriting an organizational marker from a parent.
				foreach ($model->getAttributesByElement($element) ?: [] as $attribute) {
					foreach ($attribute->getValues() as $value) {
						if ($value->getElementCode() === $element && self::hasMarker((string)$value->getDisplayValue(),$marker)) { $marked = true; }
					}
				}
			}
			$parent = (int)$model->get('parent_id');
			$rows[$id] = ['collection_id' => $id,'parent_id' => $parent,'organizational_unit' => $marked];
			if (!$parent) { return new self($rows,$element !== ''); }
			$model = new \ca_collections($parent);
		}
		throw new Failure('The collection hierarchy contains a missing or inaccessible record.');
	}
	public static function hasMarker(string $text, string $marker) : bool {
		$text = html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
		$text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is',' ',$text);
		$text = preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|tr|blockquote|h[1-6])\s*)>~i',"\n",$text);
		$text = str_replace(["\r\n","\r","\xc2\xa0"],["\n","\n",' '],html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8'));
		foreach (explode("\n",$text) as $line) { if (trim($line) === $marker) { return true; } }
		return false;
	}
	public function classify(int $id) : array {
		if (isset($this->resolved[$id])) { return $this->resolved[$id]; }
		if (!isset($this->rows[$id]) || !empty($this->rows[$id]['deleted'])) { throw new Failure('The collection is missing or deleted.'); }
		$seen = []; $current = $id; $root = null; $hasUnit = false;
		while ($current) {
			if (isset($seen[$current])) { throw new Failure('A cycle prevents determining the preservation root.'); }
			if (!isset($this->rows[$current]) || !empty($this->rows[$current]['deleted'])) { throw new Failure('The collection hierarchy contains a missing or deleted parent.'); }
			$seen[$current] = true;
			if (empty($this->rows[$current]['organizational_unit'])) { $root = $current; $hasUnit = false; }
			else { $hasUnit = true; }
			$current = (int)($this->rows[$current]['parent_id'] ?? 0);
		}
		$unit = !empty($this->rows[$id]['organizational_unit']);
		$outside = $this->requireOrganizationalParent && !$hasUnit;
		return $this->resolved[$id] = ['eligible' => !$unit && !$outside && $root === $id,'organizational_unit' => $unit,
			'root_collection_id' => $outside ? null : $root,'outside_organizational_hierarchy' => $outside];
	}
	public function requireRoot(int $id) : void {
		$status = $this->classify($id);
		if ($status['organizational_unit']) { throw new Failure('Organizational units cannot be preserved directly. Select a collection below the organizational unit.'); }
		if ($status['outside_organizational_hierarchy']) { throw new Failure('Place this collection below a marked organizational unit before preservation.'); }
		if (!$status['eligible']) { throw new Failure('Only top-level archival collections can be preserved. This record is included with preservation root #'.$status['root_collection_id'].'.'); }
	}
}
