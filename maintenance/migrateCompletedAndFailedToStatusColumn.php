<?php
/**
 * @file
 * @ingroup Maintenance
 */
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class MigrateCompletedAndFailedToStatusColumn extends LoggedUpdateMaintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Migrates data from old timestamp columns to new columns.' );
	}

	/**
	 * Get the update key name to go in the update log table
	 *
	 * @return string
	 */
	protected function getUpdateKey() {
		return __CLASS__;
	}

	/**
	 * Message to show that the update was done already and was just skipped
	 *
	 * @return string
	 */
	protected function updateSkippedMessage() {
		return 'DataDump\'s database tables have already been migrated to use the new status column.';
	}

	/**
	 * Do the actual work.
	 *
	 * @return bool True to log the update as done
	 */
	protected function doDBUpdates() {
		$dbw = $this->getDB( DB_PRIMARY );

		if ( $dbw->fieldExists( 'data_dump', 'dumps_completed', __METHOD__ ) &&
			$dbw->fieldExists( 'data_dump', 'dumps_failed', __METHOD__ )
		) {
			$res = $dbw->select(
				'data_dump',
				[
					'dumps_completed',
					'dumps_failed',
					'dumps_filename',
				],
				'',
				__METHOD__
			);

			foreach ( $res as $row ) {
				if ( (int)$row->dumps_completed === 0 && (int)$row->dumps_failed === 0 ) {
					$status = 'queued';
				} elseif ( (int)$row->dumps_failed === 1 && (int)$row->dumps_completed !== 1 ) {
					$status = 'failed';
				} elseif ( (int)$row->dumps_failed !== 1 && (int)$row->dumps_completed === 1 ) {
					$status = 'completed';
				}
				$dbw->update(
					'data_dump',
					[
						'dumps_status' => $status
					],
					[
						'dumps_completed' => (int)$row->dumps_completed,
						'dumps_failed' => (int)$row->dumps_failed,
						'dumps_filename' => $row->dumps_filename,
					],
					__METHOD__
				);
			}
		}

		return true;
	}
}

$maintClass = MigrateCompletedAndFailedToStatusColumn::class;
require_once RUN_MAINTENANCE_IF_MAIN;
