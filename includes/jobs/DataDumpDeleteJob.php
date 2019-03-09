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
			if ( $this->params['data']["delete_{$fileName}"] ) {
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

	/**
	 * @return FileBackend
	 */
	private function getBackend() {
		global $wgDataDumpFileBackend, $wgDataDumpDirectory;
		if ( $wgDataDumpFileBackend ) {
			return FileBackendGroup::singleton()->get( $wgDataDumpFileBackend );
		} else {
			static $backend = null;
			if ( !$backend ) {
				$backend = new FSFileBackend( [
					'name'           => 'dumps-backend',
					'wikiId'         => wfWikiID(),
					'lockManager'    => new NullLockManager( [] ),
					'containerPaths' => [ 'dumps-backup' => $wgDataDumpDirectory ],
					'fileMode'       => 777,
					'obResetFunc'    => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ]
				] );
			}
			return $backend;
		}
	}
}
