<?php
/** Synthetic organizational-marker parsing and hierarchy policy checks. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Eligibility.php';
require_once __DIR__.'/SyntheticCatalogue.php';
require_once __DIR__.'/stubs/ca_collections.php';
use MDPNPreservation\Config;
use MDPNPreservation\Eligibility;
function check($condition,string $message) : void { if (!$condition) { throw new RuntimeException($message); } }
function fails(callable $call,string $message) : void {
	try { $call(); } catch (Throwable $e) { check(strpos($e->getMessage(),$message) !== false,'Unexpected failure: '.$e->getMessage()); return; }
	throw new RuntimeException('Expected failure: '.$message);
}
$marker = '[[organizational_unit]]';
foreach ([$marker,"Existing note.\n\n  $marker  ","<p>Existing note.</p><p>$marker</p>","$marker<br>Other note.","\r\n$marker\r\n"] as $text) { check(Eligibility::hasMarker($text,$marker),'Recognize an exact standalone marker in plain or rich text'); }
foreach (['',"Mention $marker in a sentence.",'[[organizational_unit_extra]]',"<script>$marker</script>"] as $text) { check(!Eligibility::hasMarker($text,$marker),'Ordinary prose, lookalike tokens and scripts do not mark an organizational unit'); }
$catalogue = new SyntheticCatalogue();
$catalogue->data['ca_collections'][100] = ['collection_id' => 100,'parent_id' => null];
$catalogue->data['ca_collections'][101] = ['collection_id' => 101,'parent_id' => 100];
$catalogue->data['ca_collections'][1]['parent_id'] = 101;
$catalogue->data['ca_collections'][99]['parent_id'] = 100;
$catalogue->data['ca_collections'][88] = ['collection_id' => 88,'parent_id' => null];
$catalogue->organizationalNotes = [100 => $marker,101 => $marker];
$config = new Config(['organizational_unit_element' => 'internal_notes']);
$policy = Eligibility::load($config,$catalogue);
check($policy->classify(1)['eligible'] && $policy->classify(99)['eligible'],'The first collection below each organizational branch is eligible');
check(!$policy->classify(88)['eligible'] && $policy->classify(88)['outside_organizational_hierarchy'],'Unparented records are not eligible in organizational hierarchy mode');
fails(function() use ($policy) { $policy->requireRoot(88); },'below a marked organizational unit');
$missingTopMarker = clone $catalogue; unset($missingTopMarker->organizationalNotes[100]);
check(Eligibility::load($config,$missingTopMarker)->classify(1)['outside_organizational_hierarchy'],'A missing outer organizational marker cannot be bypassed by a marked descendant');
check($policy->classify(2)['root_collection_id'] === 1 && !$policy->classify(2)['eligible'],'Subordinates belong to the first archival collection, without inheriting its parents markers');
fails(function() use ($policy) { $policy->requireRoot(100); },'Organizational units');
fails(function() use ($policy) { $policy->requireRoot(2); },'Only top-level archival collections');
$withoutMarkers = Eligibility::load(new Config([]),$catalogue);
check($withoutMarkers->classify(100)['eligible'] && $withoutMarkers->classify(88)['eligible'] && !$withoutMarkers->classify(1)['eligible'],'An unconfigured marker field uses native hierarchy roots');
$broken = $catalogue->data['ca_collections']; $broken[100]['parent_id'] = 2;
fails(function() use ($broken) { (new Eligibility($broken))->classify(1); },'cycle');
$broken = $catalogue->data['ca_collections']; unset($broken[101]);
fails(function() use ($broken) { (new Eligibility($broken))->classify(1); },'missing or deleted parent');
$broken = $catalogue->data['ca_collections']; $broken[101]['deleted'] = 1;
fails(function() use ($broken) { (new Eligibility($broken))->classify(1); },'missing or deleted parent');
$GLOBALS['AUTH_CURRENT_USER_ID'] = 1;
ca_collections::$parents = [1=>101,101=>100,2=>1]; ca_collections::$notes = $catalogue->organizationalNotes;
check(Eligibility::forModel($config,new ca_collections(1))->classify(1) === $policy->classify(1),'Native editor checks agree with the authoritative catalogue policy');
check(!Eligibility::forModel($config,new ca_collections(2))->classify(2)['eligible'],'Native editor checks reject subordinate records');
$withoutNotesBundle = new class(1) extends ca_collections { public function hasElement($field) { return false; } };
check(Eligibility::forModel($config,$withoutNotesBundle)->classify(1)['eligible'],'A record type without the notes bundle can still be an archival root below marked parents');
fails(function() { (new Config(['organizational_unit_element'=>'ca_collections.internal_notes']))->organizationalUnitElement(); },'without a table prefix');
fails(function() { (new Config(['organizational_unit_marker'=>"bad\nmarker"]))->organizationalUnitMarker(); },'single-line');
print "Organizational markers, hierarchy roots, editor parity and invalid ancestry checks passed.\n";
