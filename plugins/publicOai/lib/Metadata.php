<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace TADL\PublicOai;

/** Render an already authorized public record through Providence's XML formatter. */
final class Metadata {
	public const MODS_NAMESPACE = 'http://www.loc.gov/mods/v3';
	public const MODS_SCHEMA = 'https://www.loc.gov/standards/mods/v3/mods-3-8.xsd';
	public const DC_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
	public const DC_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd';

	/** Return the native ca_data_exporters tree, including exactly one root. */
	public static function render(array $record, string $format): array {
		if ($format === 'mods') { return [self::mods($record)]; }
		if ($format === 'oai_dc') { return [self::dc($record)]; }
		throw new \InvalidArgumentException('Unsupported public OAI metadata format.');
	}

	private static function mods(array $record): array {
		$children = [];
		foreach (self::texts($record['titles'] ?? []) as $title) {
			$children[] = self::node('mods:titleInfo', '', [], [self::node('mods:title', $title)]);
		}
		foreach (self::texts($record['alternate_titles'] ?? []) as $title) {
			$children[] = self::node('mods:titleInfo', '', ['type' => 'alternative'], [self::node('mods:title', $title)]);
		}
		foreach ($record['identifiers'] ?? [] as $identifier) {
			if (($value = self::text($identifier['value'] ?? '')) === '') { continue; }
			$children[] = self::node('mods:identifier', $value, ['type' => self::text($identifier['type'] ?? 'local')]);
		}
		$publishers = [];
		foreach ($record['names'] ?? [] as $name) {
			if (($value = self::text($name['name'] ?? '')) === '') { continue; }
			$parts = [self::node('mods:namePart', $value)];
			foreach (self::texts($name['roles'] ?? []) as $role) {
				$parts[] = self::node('mods:role', '', [], [self::node('mods:roleTerm', $role, ['type' => 'text'])]);
				if (strtolower($role) === 'publisher') { $publishers[] = $value; }
			}
			$type = self::nameType($name['type'] ?? '');
			$children[] = self::node('mods:name', '', ['type' => $type, 'valueURI' => self::url($name['uri'] ?? '')], $parts);
		}
		$origins = [];
		foreach (self::texts($publishers) as $publisher) { $origins[] = self::node('mods:publisher', $publisher); }
		foreach ($record['dates'] ?? [] as $date) {
			$type = self::text($date['type'] ?? '');
			$element = self::dateElement($type);
			$attributes = $element === 'dateOther' ? ['type' => $type] : [];
			$qualifier = self::text($date['qualifier'] ?? '');
			if (in_array($qualifier, ['approximate', 'inferred', 'questionable'], true)) { $attributes['qualifier'] = $qualifier; }
			if (($value = self::text($date['value'] ?? '')) !== '') { $origins[] = self::node('mods:'.$element, $value, $attributes); }
			$start = self::text($date['start'] ?? '');
			$end = self::text($date['end'] ?? '');
			foreach (['start' => $start, 'end' => $end] as $point => $value) {
				if ($value === '' || ($point === 'end' && $value === $start)) { continue; }
				// Preserve unparseable dates as display text; never claim an encoding for them.
				if (!self::normalizedDate($value)) { continue; }
				$origins[] = self::node('mods:'.$element, $value, array_merge($attributes, ['encoding' => 'w3cdtf', 'point' => $point]));
			}
		}
		if ($origins) { $children[] = self::node('mods:originInfo', '', [], $origins); }
		foreach (self::texts($record['language'] ?? []) as $language) {
			$children[] = self::node('mods:language', '', [], [self::node('mods:languageTerm', $language, ['type' => 'text'])]);
		}
		foreach (self::texts($record['description'] ?? []) as $description) { $children[] = self::node('mods:abstract', $description); }
		foreach ($record['notes'] ?? [] as $note) {
			if (($value = self::text($note['value'] ?? '')) !== '') { $children[] = self::node('mods:note', $value, ['type' => self::text($note['type'] ?? '')]); }
		}
		$type = self::text($record['type'] ?? '');
		$resourceType = self::resourceType($type);
		if ($resourceType !== '') { $children[] = self::node('mods:typeOfResource', $resourceType); }
		$genres = self::texts($record['genre'] ?? []);
		if ($type !== '' && strtolower(str_replace('_', ' ', $type)) !== $resourceType) { $genres[] = $type; }
		foreach (self::texts($genres) as $genre) { $children[] = self::node('mods:genre', $genre); }
		foreach ($record['subjects'] ?? [] as $subject) {
			if (($value = self::text($subject['value'] ?? '')) === '') { continue; }
			$type = self::text($subject['type'] ?? 'topic');
			if (!in_array($type, ['topic', 'geographic', 'temporal', 'name', 'genre'], true)) { $type = 'topic'; }
			$part = $type === 'name' ? self::node('mods:name', '', [], [self::node('mods:namePart', $value)]) : self::node('mods:'.$type, $value);
			$children[] = self::node('mods:subject', '', ['authority' => self::text($subject['authority'] ?? ''), 'valueURI' => self::url($subject['uri'] ?? '')], [$part]);
		}
		$media = self::media($record['media'] ?? []);
		$physical = $record['physical'] ?? [];
		$formats = [];
		foreach (['form' => 'form', 'extent' => 'extent', 'internet_media_type' => 'internetMediaType'] as $key => $element) {
			$values = self::texts($physical[$key] ?? []);
			if ($key === 'internet_media_type') { $values = self::texts(array_merge($values, array_column($media, 'mimetype'))); }
			foreach ($values as $value) { $formats[] = self::node('mods:'.$element, $value); }
		}
		if ($formats) { $children[] = self::node('mods:physicalDescription', '', [], $formats); }
		foreach ($record['rights'] ?? [] as $right) {
			if (($value = self::text($right['value'] ?? '')) === '') { continue; }
			$children[] = self::node('mods:accessCondition', $value, ['type' => self::text($right['type'] ?? 'use and reproduction'), 'valueURI' => self::url($right['uri'] ?? '')]);
		}
		foreach ($record['relations'] ?? [] as $relation) {
			$parts = [];
			if (($title = self::text($relation['title'] ?? '')) !== '') { $parts[] = self::node('mods:titleInfo', '', [], [self::node('mods:title', $title)]); }
			if (($id = self::text($relation['identifier'] ?? '')) !== '') { $parts[] = self::node('mods:identifier', $id, ['type' => 'local']); }
			if (($url = self::url($relation['url'] ?? '')) !== '') { $parts[] = self::node('mods:location', '', [], [self::node('mods:url', $url, ['access' => 'object in context'])]); }
			if (!$parts) { continue; }
			$type = self::text($relation['type'] ?? 'related');
			$attributes = in_array($type, ['preceding', 'succeeding', 'original', 'host', 'constituent', 'series', 'otherVersion', 'otherFormat', 'isReferencedBy', 'references', 'reviewOf'], true) ? ['type' => $type] : ['otherType' => $type];
			$children[] = self::node('mods:relatedItem', '', $attributes, $parts);
		}
		$locations = [];
		if (($repository = self::text($record['repository'] ?? '')) !== '') { $locations[] = self::node('mods:physicalLocation', $repository); }
		if (($landing = self::url($record['landing_url'] ?? '')) !== '') { $locations[] = self::node('mods:url', $landing, ['access' => 'object in context', 'usage' => 'primary']); }
		$previews = [];
		foreach ($record['preview_urls'] ?? [] as $preview) { if (($url = self::url($preview)) !== '') { $previews[$url] = $url; } }
		foreach ($previews as $url) { $locations[] = self::node('mods:url', $url, ['access' => 'preview']); }
		if (count($media) === 1) {
			if ($media[0]['context_url'] !== '') { $locations[] = self::node('mods:url', $media[0]['context_url'], ['access' => 'object in context', 'displayLabel' => 'View representation']); }
			$locations[] = self::node('mods:url', $media[0]['url'], ['access' => 'raw object']);
			if ($media[0]['caption'] !== '') { $children[] = self::node('mods:note', $media[0]['caption'], ['type' => 'media caption']); }
		} elseif (count($media) > 1) {
			foreach ($media as $file) {
				$parts = [];
				if ($file['title'] !== '') { $parts[] = self::node('mods:titleInfo', '', [], [self::node('mods:title', $file['title'])]); }
				if ($file['caption'] !== '') { $parts[] = self::node('mods:note', $file['caption'], ['type' => 'caption']); }
				if ($file['mimetype'] !== '') { $parts[] = self::node('mods:physicalDescription', '', [], [self::node('mods:internetMediaType', $file['mimetype'])]); }
				$links = [];
				if ($file['context_url'] !== '') { $links[] = self::node('mods:url', $file['context_url'], ['access' => 'object in context', 'displayLabel' => 'View representation']); }
				$links[] = self::node('mods:url', $file['url'], ['access' => 'raw object']);
				$parts[] = self::node('mods:location', '', [], $links);
				$children[] = self::node('mods:relatedItem', '', ['type' => 'constituent'], $parts);
			}
		}
		if ($locations) { $children[] = self::node('mods:location', '', [], $locations); }
		return self::node('mods:mods', '', ['xmlns:mods' => self::MODS_NAMESPACE, 'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance', 'version' => '3.8', 'xsi:schemaLocation' => self::MODS_NAMESPACE.' '.self::MODS_SCHEMA], $children);
	}

	private static function dc(array $record): array {
		$values = [];
		$add = static function (string $element, $text) use (&$values): void {
			foreach (self::texts($text) as $value) { $values[$element][$value] = $value; }
		};
		$add('title', array_merge(self::texts($record['titles'] ?? []), self::texts($record['alternate_titles'] ?? [])));
		foreach ($record['identifiers'] ?? [] as $identifier) { $add('identifier', $identifier['value'] ?? ''); }
		$add('identifier', self::url($record['landing_url'] ?? ''));
		foreach (self::media($record['media'] ?? []) as $file) { $add('identifier', $file['context_url']); $add('identifier', $file['url']); }
		foreach ($record['names'] ?? [] as $name) {
			$value = self::text($name['name'] ?? '');
			if ($value === '') { continue; }
			$roles = self::texts($name['roles'] ?? []);
			foreach ($roles ?: ['contributor'] as $role) {
				$element = in_array(strtolower($role), ['creator', 'publisher'], true) ? strtolower($role) : 'contributor';
				$add($element, $element === 'contributor' && strtolower($role) !== 'contributor' ? ucfirst($role).': '.$value : $value);
			}
		}
		foreach ($record['dates'] ?? [] as $date) { $add('date', $date['value'] ?? ''); }
		foreach (['description', 'language', 'type'] as $element) { $add($element, $record[$element] ?? []); }
		$add('type', $record['genre'] ?? []);
		foreach ($record['subjects'] ?? [] as $subject) { $add(($subject['type'] ?? '') === 'geographic' || ($subject['type'] ?? '') === 'temporal' ? 'coverage' : 'subject', $subject['value'] ?? ''); }
		foreach ($record['physical'] ?? [] as $physical) { $add('format', $physical); }
		foreach (self::media($record['media'] ?? []) as $file) { $add('format', $file['mimetype']); if ($file['caption'] !== '') { $add('description', 'Media caption: '.$file['caption']); } }
		foreach ($record['notes'] ?? [] as $note) {
			$type = self::text($note['type'] ?? '');
			$value = self::text($note['value'] ?? '');
			if ($value === '') { continue; }
			$element = in_array(strtolower($type), ['source', 'coverage'], true) ? strtolower($type) : 'description';
			$add($element, $element === 'description' && $type !== '' ? ucfirst($type).': '.$value : $value);
		}
		foreach ($record['rights'] ?? [] as $right) { $add('rights', $right['value'] ?? ''); if (($uri = self::url($right['uri'] ?? '')) !== '') { $add('rights', $uri); } }
		foreach ($record['relations'] ?? [] as $relation) {
			$add('relation', $relation['title'] ?? '');
			$add('relation', self::url($relation['url'] ?? ''));
			$add('relation', $relation['identifier'] ?? '');
		}
		$children = [];
		foreach ($values as $element => $texts) { foreach ($texts as $text) { $children[] = self::node('dc:'.$element, $text); } }
		return self::node('oai_dc:dc', '', ['xmlns:oai_dc' => self::DC_NAMESPACE, 'xmlns:dc' => 'http://purl.org/dc/elements/1.1/', 'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation' => self::DC_NAMESPACE.' '.self::DC_SCHEMA], $children);
	}

	/** Data is text, never pre-escaped XML. The native formatter performs escaping. */
	private static function node(string $element, string $text = '', array $attributes = [], array $children = []): array {
		$nodes = [];
		foreach ($attributes as $name => $value) { if (($value = self::text($value)) !== '') { $nodes[] = ['element' => '@'.$name, 'text' => $value, 'children' => []]; } }
		return ['element' => $element, 'text' => self::text($text), 'children' => array_merge($nodes, $children)];
	}

	private static function text($value): string {
		if (!is_scalar($value)) { return ''; }
		$value = mb_convert_encoding((string)$value, 'UTF-8', 'UTF-8');
		return trim(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value));
	}

	private static function texts($values): array {
		$clean = [];
		foreach (is_array($values) ? $values : [$values] as $value) { if (($value = self::text($value)) !== '') { $clean[$value] = $value; } }
		return array_values($clean);
	}

	private static function url($value): string {
		if (!is_scalar($value)) { return ''; }
		$value = trim((string)$value);
		if (preg_match('/[\x00-\x20\x7f]/', $value) || !mb_check_encoding($value, 'UTF-8')) { return ''; }
		$parts = parse_url($value);
		if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || !filter_var($value, FILTER_VALIDATE_URL)) { return ''; }
		return $value;
	}

	private static function media(array $files): array {
		$media = [];
		foreach ($files as $file) {
			if (($url = self::url($file['url'] ?? '')) === '') { continue; }
			$media[$url] = ['url' => $url, 'context_url' => self::url($file['context_url'] ?? ''), 'mimetype' => self::text($file['mimetype'] ?? ''), 'title' => self::text($file['title'] ?? ''), 'caption' => self::text($file['caption'] ?? '')];
		}
		return array_values($media);
	}

	private static function nameType($value): string {
		$types = ['ind' => 'personal', 'individual' => 'personal', 'personal' => 'personal', 'org' => 'corporate', 'organization' => 'corporate', 'corporate' => 'corporate', 'fam' => 'family', 'family' => 'family', 'conference' => 'conference'];
		return $types[strtolower(self::text($value))] ?? '';
	}

	private static function dateElement(string $value): string {
		$types = ['created' => 'dateCreated', 'creation' => 'dateCreated', 'datecreated' => 'dateCreated', 'date created' => 'dateCreated', 'recording date' => 'dateCreated', 'issued' => 'dateIssued', 'publication' => 'dateIssued', 'dateissued' => 'dateIssued', 'date issued' => 'dateIssued', 'captured' => 'dateCaptured', 'datecaptured' => 'dateCaptured', 'copyright' => 'copyrightDate', 'copyrighted' => 'copyrightDate', 'copyrightdate' => 'copyrightDate', 'date copyrighted' => 'copyrightDate', 'valid' => 'dateValid', 'date valid' => 'dateValid', 'modified' => 'dateModified', 'date modified' => 'dateModified'];
		return $types[strtolower($value)] ?? 'dateOther';
	}

	private static function normalizedDate(string $value): bool {
		if (!preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/D', $value, $parts) || (int)$parts[1] < 1) { return false; }
		if (!isset($parts[2])) { return true; }
		if ((int)$parts[2] < 1 || (int)$parts[2] > 12) { return false; }
		return !isset($parts[3]) || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}

	private static function resourceType(string $value): string {
		$value = strtolower(str_replace('_', ' ', $value));
		$types = ['still image' => 'still image', 'text' => 'text', 'document' => 'text', 'moving image' => 'moving image', 'sound' => 'sound recording', 'sound recording' => 'sound recording', 'sound recording-musical' => 'sound recording-musical', 'sound recording-nonmusical' => 'sound recording-nonmusical', 'physical object' => 'three dimensional object', 'three dimensional object' => 'three dimensional object', 'software' => 'software, multimedia', 'interactive resource' => 'software, multimedia', 'dataset' => 'dataset', 'cartographic' => 'cartographic', 'notated music' => 'notated music', 'mixed material' => 'mixed material'];
		return $types[$value] ?? '';
	}
}
