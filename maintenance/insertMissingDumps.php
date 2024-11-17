<?php

namespace Miraheze\DataDump\Maintenance;

$IP ??= getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3);
require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\DataDump\DataDump;

class InsertMissingDumps extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Import missing dumps from the backend to the data_dump table' );
		$this->requireExtension( 'DataDump' );
	}

	public function execute(): void {
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
			'topOnly' => true,
		] ) );

		$chunkedFiles = [];
		foreach ( $dumpFiles as $file ) {
			// Group files by their base names (strip .part<number>).
			// This is done to support chunked files.
			if ( preg_match( '/^(.*)\.part\d+$/', $file, $matches ) ) {
				$chunkedFiles[ $matches[1] ][] = $file;
			} else {
				$chunkedFiles[$file] = [ $file ];
			}
		}

		// Process only the first chunk of each group
		foreach ( $chunkedFiles as $baseFile => $files ) {
			if ( in_array( $baseFile, $existingDumps ) ) {
				continue;
			}

			// Take the first chunk (or the whole file if not chunked)
			$firstChunk = $files[0];
			$fileSize = $backend->getFileSize( [
				'src' => "$storagePath/$firstChunk",
			] );

			$fileStat = $backend->getFileStat( [
				'src' => "$storagePath/$firstChunk",
			] );

			$fileExtension = substr( $baseFile, strpos( $baseFile, '.' ) + 1 );

			// Determine the dump type
			$dumpType = 'unknown';
			foreach ( $this->getConfig()->get( 'DataDump' ) as $type => $dumpConfig ) {
				if ( $dumpConfig['file_ending'] === ".$fileExtension" ) {
					$dumpType = $type;
					break;
				}
			}

			// Insert the dump into the data_dump table
			$db->insert( 'data_dump', [
				'dumps_filename' => $baseFile,
				'dumps_failed' => 0,
				'dumps_size' => $fileSize,
				'dumps_status' => 'completed',
				'dumps_timestamp' => $db->timestamp( $fileStat['mtime'] ?? 0 ),
				'dumps_type' => $dumpType,
			] );
		}
	}
}

$maintClass = InsertMissingDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
