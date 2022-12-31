<?php

use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class DeleteOldDumps extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete the oldest dumps if the dump limit is exceeded' );
		$this->requireExtension( 'DataDump' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$db = $this->getDB( DB_PRIMARY );
		$res = $db->select( 'data_dump', '*', [], __METHOD__, [ 'ORDER BY' => 'dumps_timestamp ASC' ] );

		$backend = DataDump::getBackend();
		$storagePath = $backend->getContainerStoragePath( 'dumps-backup' );

		$dumpCount = 0;
		foreach ( $res as $row ) {
			$dumpType = $row->dumps_type;
			$dumpLimit = $config->get( 'DataDump' )[$dumpType]['limit'];

			if ( $dumpLimit < 0 ) {
				continue;
			}

			$dumpCount++;
			if ( $dumpCount > $dumpLimit ) {
				# Delete the dump from the data_dump table
				$db->delete( 'data_dump', [ 'dumps_filename' => $row->dumps_filename ], __METHOD__ );

				# Delete the dump file from storage
				$backend->delete( [ 'src' => "$storagePath/{$row->dumps_filename}" ] );
			}
		}
	}
}

$maintClass = DeleteOldDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
