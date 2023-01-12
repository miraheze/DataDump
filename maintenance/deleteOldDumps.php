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
		$this->addOption( 'dry-run', 'Perform a dry run and do not actually delete any dumps' );

		$this->requireExtension( 'DataDump' );
	}

	public function execute() {
		$db = $this->getDB( DB_PRIMARY );
		$dryRun = $this->getOption( 'dry-run', false );

		// Get the dump types and their limits from the config
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$dumpTypes = $config->get( 'DataDump' );

		// Loop through each dump type
		foreach ( $dumpTypes as $dumpType => $typeConfig ) {
			$limit = $typeConfig['limit'];

			// If the limit is 0 or less, there is no limit for this dump type
			if ( $limit <= 0 ) {
				continue;
			}

			// Get the current number of dumps of this type
			$numDumps = (int)$db->selectField(
				'data_dump',
				'COUNT(*)',
				[ 'dumps_type' => $dumpType ],
				__METHOD__
			);

			// If the number of dumps is already at or below the limit, there is nothing to do
			if ( $numDumps <= $limit ) {
				continue;
			}

			// Get the oldest dump of this type
			$oldestDump = $db->selectRow(
				'data_dump',
				[ 'dumps_filename' ],
				[ 'dumps_type' => $dumpType ],
				__METHOD__,
				[ 'ORDER BY' => 'dumps_timestamp ASC' ]
			);

			// If there is no oldest dump, there is nothing to do
			if ( !$oldestDump ) {
				continue;
			}

			$oldestFilename = $oldestDump->dumps_filename;

			// Delete the oldest dump
			if ( !$dryRun ) {
				$backend = DataDump::getBackend();
				$storagePath = $backend->getContainerStoragePath( 'dumps-backup' );
				$backend->delete( [ 'src' => "$storagePath/$oldestFilename" ] );

				// Delete the dump from the data_dump table
				$db->delete(
					'data_dump',
					[ 'dumps_filename' => $oldestFilename ],
					__METHOD__
				);
			} else {
				$this->output( "Would delete dump $oldestFilename\n" );
			}
		}
	}
}

$maintClass = DeleteOldDumps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
