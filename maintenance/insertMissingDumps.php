<?php

use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class InsertMissingDumps extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import missing dumps from the backend to the data_dump table' );
		$this->requireExtension( 'DataDump' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$db = $this->getDB( DB_PRIMARY );
		$res = $db->select( 'data_dump', '*' );

		$existingDumps = [];
		foreach ( $res as $row ) {
			$existingDumps[] = $row->dumps_filename;
		}

		$backend = DataDump::getBackend();
		$storagePath = $backend->getContainerStoragePath( 'dumps-backup' );
		$dumpFiles = iterator_to_array( $backend->getFileList( [
			'dir' => $storagePath,
			'adviseStat' => true,
			'topOnly' => true
		] ) );

		$missingDumps = array_diff( array_values( $dumpFiles ), $existingDumps );
		foreach ( $missingDumps as $dump ) {
			$fileSize = $backend->getFileSize( [
				'src' => "$storagePath/$dump"
			] );

			$fileStat = $backend->getFileStat( [
				'src' => "$storagePath/$dump"
			] );

			$fileExtension = substr( $dump, strpos( $dump, '.' ) + 1 );

			# Determine the dump type
			$dumpType = 'unknown';
			foreach ( $config->get( 'DataDump' ) as $type => $dumpConfig ) {
				if ( $dumpConfig['file_ending'] == ".$fileExtension" ) {
					$dumpType = $type;
					break;
				}
			}

			# Insert the dump into the data_dump table
			$db->insert( 'data_dump', [
				'dumps_filename' => $dump,
				'dumps_failed' => 0,
				'dumps_size' => $fileSize,
				'dumps_status' => 'completed',
				'dumps_timestamp' => $db->timestamp( $fileStat['mtime'] ?? 0 ),
				'dumps_type' => $dumpType
			] );
		}
	}
}

$maintClass = InsertMissingDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
