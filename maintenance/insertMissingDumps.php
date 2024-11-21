<?php

namespace Miraheze\DataDump\Maintenance;

$IP ??= getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\DataDump\ConfigNames;

class InsertMissingDumps extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Import missing dumps from the backend to the data_dump table' );
		$this->requireExtension( 'DataDump' );
	}

	public function execute(): void {
		$dbw = $this->getDB( DB_PRIMARY );
		$res = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'data_dump' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$existingDumps = [];
		foreach ( $res as $row ) {
			$existingDumps[] = $row->dumps_filename;
		}

		$backend = $this->getServiceContainer()->get( 'DataDumpFileBackend' )->getBackend();
		$storagePath = $backend->getContainerStoragePath( 'dumps-backup' );
		$dumpFiles = iterator_to_array( $backend->getFileList( [
			'dir' => $storagePath,
			'adviseStat' => true,
			'topOnly' => true,
		] ) );

		$chunkedFiles = [];
		foreach ( $dumpFiles as $file ) {
			// Group files by their base names (strip .part<number>).
			if ( preg_match( '/^(.*)\.part\d+$/', $file, $matches ) ) {
				$chunkedFiles[ $matches[1] ][] = $file;
			} else {
				$chunkedFiles[$file] = [ $file ];
			}
		}

		// Process each group of files
		foreach ( $chunkedFiles as $baseFile => $files ) {
			if ( in_array( $baseFile, $existingDumps ) ) {
				continue;
			}

			// Calculate the total size of all chunks
			$totalSize = 0;
			foreach ( $files as $chunk ) {
				$totalSize += $backend->getFileSize( [
					'src' => "$storagePath/$chunk",
				] );
			}

			$lastChunk = end( $files );
			$fileStat = $backend->getFileStat( [
				'src' => "$storagePath/$lastChunk",
			] );

			$fileExtension = substr( $baseFile, strpos( $baseFile, '.' ) + 1 );

			// Determine the dump type
			$dumpType = 'unknown';
			foreach ( $this->getConfig()->get( ConfigNames::DataDump ) as $type => $dumpConfig ) {
				if ( $dumpConfig['file_ending'] === ".$fileExtension" ) {
					$dumpType = $type;
					break;
				}
			}

			// Insert the dump into the data_dump table
			$dbw->newInsertQueryBuilder()
				->insertInto( 'data_dump' )
				->row( [
					'dumps_filename' => $baseFile,
					'dumps_size' => $totalSize,
					'dumps_status' => 'completed',
					'dumps_timestamp' => $dbw->timestamp( $fileStat['mtime'] ?? 0 ),
					'dumps_type' => $dumpType,
				] )
				->caller( __METHOD__ )
				->execute();
		}
	}
}

$maintClass = InsertMissingDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
