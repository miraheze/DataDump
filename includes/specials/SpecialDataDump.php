<?php

/**
 * Special Page for users to generate there own wiki dump e.g xml dump, image dump.
 *
 * Primarily made for wiki farms.
 *
 * @author Paladox
 */
class SpecialDataDump extends SpecialPage {

	public function __construct() {
		parent::__construct( 'DataDump', 'view-dump' );
	}

	public function execute( $par ) {
		global $wgDataDump;

		$this->setHeaders();
		$this->outputHeader();

		$this->checkPermissions();

		$out = $this->getOutput();

		if ( !$wgDataDump ) {
			$out->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		$pager = new DataDumpPager();
		$table = $pager->getBody();

		$out->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
