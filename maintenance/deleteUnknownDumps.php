<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class DeleteUnknownDumps extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete all dumps with types that do not exist in the $wgDataDump configuration' );
		$this->addOption( 'dry-run', 'Do not delete any dumps, just output what would be deleted' );

		$this->requireExtension( 'DataDump' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dumpTypes = array_keys( $config->get( 'Dumps' ) );
		$dryRun = $this->getOption( 'dry-run', false );

		$db = $this->getDB( DB_PRIMARY );
		$res = $db->select( 'data_dump', '*', [], __METHOD__ );

		$backend = DataDump::getBackend();
		$storagePath = $backend->getContainerStoragePath( 'dumps-backup' );

		foreach ( $res as $row ) {
			if ( !in_array( $row->dumps_type, $dumpTypes ) ) {
				if ( !$dryRun ) {
					# Delete the dump from the data_dump table
					$db->delete( 'data_dump', [ 'dumps_filename' => $row->dumps_filename ], __METHOD__ );

					# Delete the dump file from storage
					$backend->delete( [ 'src' => "$storagePath/{$row->dumps_filename}" ] );
				} else {
					# Output what would be deleted if this was not a dry run
					$this->output( "Would delete dump with filename {$row->dumps_filename} and type {$row->dumps_type}\n" );
				}
			}
		}

		$this->output( "Done\n" );
	}
}

$maintClass = DeleteUnknownDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
