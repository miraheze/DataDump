<?php

/**
 * Used to delete dumps
 *
 * @author Paladox
 */
class DataDumpDeleteJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpDeleteJob', $title, $params );
	}

	public function run() {
		$dbw = wfGetDB( DB_MASTER );

		foreach ( $this->params['fileNames'] as $fileName ) {
			if ( isset( $this->params['data']["delete_{$fileName}"] ) &&
				$this->params['data']["delete_{$fileName}"]
			) {
				$backend = DataDump::getBackend();
				$fileBackend =
					$backend->getRootStoragePath() . '/dumps-backup/' . $fileName;
				$delete = $backend->quickDelete( [
					'src' => $fileBackend,
				] );
				if ( $delete->isOK() ) {
					$dbw->delete(
						'data_dump',
						[
							'dumps_filename' => $fileName
						],
						__METHOD__
					);
				} else {
					$dbw->update(
						'data_dump',
						[
							'dumps_failed' => 1
						],
						[
							'dumps_filename' => $fileName
						],
						__METHOD__
					);
				}
			}
		}

		return true;
	}
}
