<?php

use MediaWiki\MediaWikiServices;

class DataDumpPager extends TablePager {
	public function __construct( $type ) {
		$this->mDb = self::getDataDumpWikiDb();
		$this->fileType = $type;
		parent::__construct( $this->getContext() );
	}

	private static function getDataDumpWikiDb() {
		global $wgDBname;

		$factory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $factory->getMainLB( $wgDBname );

		return $lb->getConnectionRef( DB_REPLICA, 'data_dump', $wgDBname );
	}

	public function getFieldNames() {
		static $headers = null;

		$headers = [
			'dumps_filename' => 'datadump-filename',
			'dumps_failed' => 'datadump-failed',
			'dumps_completed' => 'datadump-completed',
			'dumps_action' => 'datadump-actions',
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}
          
		//$msg .= $this->msg( 'datadump-actions' )->text();

		return $headers;
	}

	function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'dumps_filename':
				$formatted = $row->dumps_filename;
				break;
			case 'dumps_failed':
				$formatted = $row->dumps_failed;
				//$formatted = "<img src=\"{$row->files_url}\" style=\"width:135px;height:135px;\">";
				break;
			case 'dumps_completed':
				$formatted = $row->dumps_completed;
				//$formatted = "<a href=\"{$row->files_page}\">{$row->files_name}</a>";
				break;
			case 'dumps_action':
				$formatted = $row->dumps_completed;
				//$formatted = "<a href=\"{$row->files_page}\">{$row->files_name}</a>";
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	function getQueryInfo() {
		global $wgUser;

		$info = [
			'tables' => [ 'data_dump' ],
			'fields' => [
				'dumps_filename',
				'dumps_failed',
				'dumps_completed',
				'dumps_type'
			],
			'conds' => [
				'dumps_type' => $this->fileType,
			],
			'joins_conds' => [],
		];

		return $info;
	}

	function getDefaultSort() {
		return 'dumps_filename';
	}

	function isFieldSortable( $name ) {
		return true;
	}
}