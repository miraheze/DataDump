<?php

namespace Miraheze\DataDump\Maintenance;

$IP ??= getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

use LoggedUpdateMaintenance;

class MigrateCompletedAndFailedToStatusColumn extends LoggedUpdateMaintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Migrates data from completed/failed dump column to new status column.' );
		$this->requireExtension( 'DataDump' );
	}

	protected function getUpdateKey(): string {
		return __CLASS__;
	}

	protected function doDBUpdates(): bool {
		$dbw = $this->getDB( DB_PRIMARY );

		if ( $dbw->fieldExists( 'data_dump', 'dumps_completed', __METHOD__ ) &&
			$dbw->fieldExists( 'data_dump', 'dumps_failed', __METHOD__ )
		) {
			$res = $dbw->newSelectQueryBuilder()
				->table( 'data_dump' )
				->fields( [
					'dumps_completed',
					'dumps_failed',
					'dumps_filename',
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				$status = '';
				if ( (int)$row->dumps_completed === 0 && (int)$row->dumps_failed === 0 ) {
					$status = 'queued';
				} elseif ( (int)$row->dumps_failed === 1 && (int)$row->dumps_completed !== 1 ) {
					$status = 'failed';
				} elseif ( (int)$row->dumps_failed !== 1 && (int)$row->dumps_completed === 1 ) {
					$status = 'completed';
				}

				$dbw->newUpdateQueryBuilder()
					->update( 'data_dump' )
					->set( [ 'dumps_status' => $status ] )
					->where( [
						'dumps_completed' => $row->dumps_completed,
						'dumps_failed' => $row->dumps_failed,
						'dumps_filename' => $row->dumps_filename,
					] )
					->caller( __METHOD__ )
					->execute();
			}
		}

		return true;
	}
}

$maintClass = MigrateCompletedAndFailedToStatusColumn::class;
require_once RUN_MAINTENANCE_IF_MAIN;
