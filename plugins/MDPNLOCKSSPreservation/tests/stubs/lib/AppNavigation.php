<?php
/** Synthetic native navigation; records the context supplied by the controller. */
class AppNavigation {
	public static $lastRequest;
	public function __construct($request,$response) { self::$lastRequest = $request; }
	public function getHTMLWidgets() { return '<h4 data-screen="'.self::$lastRequest->actionExtra.'">Native collection inspector</h4>'; }
	public function getHTMLSideNav($id,$options) {
		self::$lastRequest->setActionExtra('SyntheticScreen');
		return '<h2><a class="sf-menu-selected" href="/editor/collections/CollectionEditor/Edit">Basic Info</a></h2>';
	}
}
