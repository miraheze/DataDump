<?php

namespace Miraheze\DataDump\Maintenance;

$IP ??= getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Miraheze\DataDump\ConfigNames;

class DeleteUnknownDumps extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Delete all dumps with types that do not exist in the $wgDataDump configuration' );
		$this->addOption( 'dry-run', 'Do not delete any dumps, just output what would be deleted' );

		$this->requireExtension( 'DataDump' );
	}

	public function execute(): void {
		$dumpTypes = array_keys( $this->getConfig()->get( ConfigNames::DataDump ) );
		$dryRun = $this->getOption( 'dry-run', false );

		$dbw = $this->getDB( DB_PRIMARY );
		$res = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'data_dump' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			if ( !in_array( $row->dumps_type, $dumpTypes ) ) {
				if ( !$dryRun ) {
					// Delete the dump file from storage
					$this->deleteFileChunks( $row->dumps_filename );

					// Delete the dump from the data_dump table
					$dbw->newDeleteQueryBuilder()
						->deleteFrom( 'data_dump' )
						->where( [ 'dumps_filename' => $row->dumps_filename ] )
						->caller( __METHOD__ )
						->execute();
				} else {
					// Output what would be deleted if this was not a dry run
					$this->output(
						"Would delete dump with filename {$row->dumps_filename} and type {$row->dumps_type}\n"
					);
				}
			}
		}

		$this->output( "Done\n" );
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

$maintClass = DeleteUnknownDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
