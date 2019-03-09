<?php

/**
 * Used to generate dump
 */
class DataDumpDeleteJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpDeleteJob', $title, $params );
	}

	public function run() {
		$dbw = wfGetDB( DB_MASTER );

		foreach ( $this->params['fileNames'] as $fileName ) {
			if ( $this->params['data']["delete_{$fileName}"] ) {
				$backend = SpecialDataDump::getBackend();
				$fileBackend = $backend->getRootStoragePath() . '/dumps-backup/';
				$delete = $backend->quickDelete( [
					'src' => $fileBackend . $fileName,
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
