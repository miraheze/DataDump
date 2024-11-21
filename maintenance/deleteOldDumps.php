<?php

namespace Miraheze\DataDump\Maintenance;

$IP ??= getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\DataDump\ConfigNames;
use Wikimedia\Rdbms\SelectQueryBuilder;

class DeleteOldDumps extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Delete the oldest dumps if the dump limit is exceeded' );
		$this->addOption( 'dry-run', 'Perform a dry run and do not actually delete any dumps' );

		$this->requireExtension( 'DataDump' );
	}

	public function execute(): void {
		$dbw = $this->getDB( DB_PRIMARY );
		$dryRun = $this->getOption( 'dry-run', false );

		// Get the dump types and their limits from the config
		$dumpTypes = $this->getConfig()->get( ConfigNames::DataDump );

		// Loop through each dump type
		foreach ( $dumpTypes as $dumpType => $typeConfig ) {
			$limit = $typeConfig['limit'];

			// If the limit is 0 or less, there is no limit for this dump type
			if ( $limit <= 0 ) {
				continue;
			}

			// Get the current number of dumps of this type
			$numDumps = $dbw->newSelectQueryBuilder()
				->select( '*' )
				->from( 'data_dump' )
				->where( [ 'dumps_type' => $dumpType ] )
				->caller( __METHOD__ )
				->fetchRowCount();

			// If the number of dumps is already at or below the limit, there is nothing to do
			if ( $numDumps <= $limit ) {
				continue;
			}

			// Get the oldest dump of this type
			$oldestDump = $dbw->newSelectQueryBuilder()
				->select( 'dumps_filename' )
				->from( 'data_dump' )
				->where( [ 'dumps_type' => $dumpType ] )
				->orderBy( 'dumps_timestamp', SelectQueryBuilder::SORT_ASC )
				->caller( __METHOD__ )
				->fetchRow();

			// If there is no oldest dump, there is nothing to do
			if ( !$oldestDump ) {
				continue;
			}

			$oldestFilename = $oldestDump->dumps_filename;

			// Delete the oldest dump
			if ( !$dryRun ) {
				$this->deleteFileChunks( $oldestFilename );

				// Delete the dump from the data_dump table
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'data_dump' )
					->where( [ 'dumps_filename' => $oldestFilename ] )
					->caller( __METHOD__ )
					->execute();
			} else {
				$this->output( "Would delete dump $oldestFilename\n" );
			}
		}
	}

	private function deleteFileChunks( string $fileName ): void {
		$backend = $this->getServiceContainer()->get( 'DataDumpFileBackend' )->getBackend();
		$fileBackend = $backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName;
		$chunkIndex = 0;

		while ( $backend->fileExists( [ 'src' => $fileBackend . '.part' . $chunkIndex ] ) ) {
			$chunkFileBackend = $fileBackend . '.part' . $chunkIndex;
			$delete = $backend->quickDelete( [ 'src' => $chunkFileBackend ] );
			if ( !$delete->isOK() ) {
				$this->fatalError( 'Failed to delete ' . $chunkFileBackend );
			}
			$chunkIndex++;
		}

		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [ 'src' => $fileBackend ] );
			if ( !$delete->isOK() ) {
				$this->fatalError( 'Failed to delete ' . $fileBackend );
			}
		}
	}
}

$maintClass = DeleteOldDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
