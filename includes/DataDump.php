<?php

use MediaWiki\MediaWikiServices;

/**
 * Stores shared code to use in multiple places.
 *
 * @author Paladox
 */
class DataDump {
	/**
	 * @return FileBackend
	 */
	public static function getBackend() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getConfigFactory()->makeConfig( 'datadump' );

		$fileBackend = $config->get( 'DataDumpFileBackend' );
		if ( $fileBackend != '' ) {
			return $services->getFileBackendGroup()->get( $fileBackend );
		} else {
			static $backend = null;
			if ( !$backend ) {
				$dirConfig = $config->get( 'DataDumpDirectory' );
				$uploadDir = $config->get( 'UploadDirectory' );
				$backend = new FSFileBackend( [
					'name'           => 'dumps-backend',
					'wikiId'         => WikiMap::getCurrentWikiId(),
					'lockManager'    => new NullLockManager( [] ),
					'containerPaths' => [ 'dumps-backup' => $dirConfig ?: "{$uploadDir}/dumps" ],
					'fileMode'       => 0777,
					'obResetFunc'    => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ]
				] );
			}
			return $backend;
		}
	}
}
