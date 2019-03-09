<?php

/**
 * Used to generate/delete dumps
 */
class DataDumpJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpJob', $title, $params );
	}

	public function run() {
		$dbw = wfGetDB( DB_MASTER );

		if ( $this->params['action'] == 'delete' ) {
			foreach ( $this->params['fileNames'] as $fileName ) {
				if ( $this->params['data']["delete_{$fileName}"] ) {
					$backend = $this->getBackend();
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
		}

		/*$res = $dbw->select(
			'page',
			[
				'page_title',
				'page_id',
			],
			[
				'page_namespace' => $nsSearch,
				"page_title LIKE '$pagePrefix%'"
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$pageTitle = $row->page_title;
			$pageID = $row->page_id;

			if ( $nsSearch == 0 ) {
				$replace = '';
				$newTitle = str_replace( $pagePrefix, $replace, $pageTitle );
			} else {
				$newTitle = $pageTitle;
			}

			if ( $this->params['action'] !== 'create' ) {
				if ( $this->pageExists( $newTitle, $nsTo, $dbw ) ) {
					$newTitle .= '~' . $this->params['nsName'];
				}
			}

			$dbw->update(
				'page',
				[
					'page_namespace' => $nsTo,
					'page_title' => $newTitle
				],
				[
					'page_id' => $pageID
				],
				__METHOD__
			);

			// Update recentchanges as this is not normally done
			$dbw->update(
				'recentchanges',
				[
					'rc_namespace' => $nsTo,
					'rc_title' => $newTitle
				],
				[
					'rc_namespace' => $nsSearch,
					'rc_title' => $pageTitle
				],
				__METHOD__
			);
		}*/
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