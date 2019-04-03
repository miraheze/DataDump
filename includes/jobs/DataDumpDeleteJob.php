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
                                if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
					$delete = $backend->quickDelete( [
						'src' => $fileBackend,
					] );
					if ( $delete->isOK() ) {
						$this->onDelete( $dbw, $fileName );
					} else {
						$this->onFailure( $dbw, $fileName );
					}
				} else {
					$this->onDelete( $dbw, $fileName );
				}
			}
		}

		return true;
	}
          
        private function onDelete( $dbw, $fileName ) {
		$dbw->delete(
			'data_dump',
			[
				'dumps_filename' => $fileName
			],
			__METHOD__
		);
	}
  
        private function onFailure( $dbw, $fileName ) {
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
