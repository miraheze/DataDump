<?php

use MediaWiki\MediaWikiServices;

class DataDumpPager extends TablePager {
	public function __construct() {
		$this->mDb = wfGetDB( DB_MASTER );

		if ( $this->getRequest()->getText( 'sort', 'dumps_date' ) == 'dumps_date' ) {
			$this->mDefaultDirection = IndexPager::DIR_DESCENDING;
		} else {
			$this->mDefaultDirection = IndexPager::DIR_ASCENDING;
		}

		parent::__construct( $this->getContext() );
	}

	public function getFieldNames() {
		static $headers = null;

		$headers = [
			'dumps_timestamp' => 'listfiles_date',
			'dumps_filename'  => 'name',
			'dumps_type'      => 'type',
			'dumps_ready'     => 'ready',
			'dumps_delete'    => 'delete',
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'dumps_timestamp':
				$time = isset( $row->dumps_timestamp ) ? $row->dumps_timestamp : '';
				$formatted = htmlspecialchars( $this->getLanguage()->userTimeAndDate( $time, $this->getUser() ) );
				break;
			case 'dumps_filename';
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
						'/download/' . $row->dumps_filename;
				$formatted = Linker::makeExternalLink( $url, $row->dumps_filename );
				break;
			case 'dumps_type':
				$formatted = $row->dumps_type;
				break;
			case 'dumps_ready':
				if ($row->dumps_completed == 1) {
					$formatted = "Ready";
				} else if ( $row->dumps_failed == 1 ) {
					$formatted = "Failed";
				} else {
					$formatted = "Queued";
				}
				break;
			case 'dumps_delete':
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
					'/delete/' . $row->dumps_filename;
				$formatted = Linker::makeExternalLink( $url, 'Delete' );
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	public function getQueryInfo() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$info = [
			'tables' => [ 'data_dump' ],
			'fields' => [ 'dumps_completed', 'dumps_filename', 'dumps_failed', 'dumps_timestamp', 'dumps_type' ],
			'conds' => [],
			'joins_conds' => [],
		];

		return $info;
	}

	public function getDefaultSort() {
		return 'dumps_timestamp';
	}

	public function isFieldSortable( $name ) {
		return true;
	}
}
