<?php

namespace Miraheze\DataDump;

use FileBackend;
use FSFileBackend;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\StreamFile;
use MediaWiki\WikiMap\WikiMap;
use NullLockManager;

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
		$config = $services->getConfigFactory()->makeConfig( 'DataDump' );

		$fileBackend = $config->get( 'DataDumpFileBackend' );
		if ( $fileBackend != '' ) {
			return $services->getFileBackendGroup()->get( $fileBackend );
		} else {
			static $backend = null;
			if ( !$backend ) {
				$dirConfig = $config->get( 'DataDumpDirectory' );
				$uploadDir = $config->get( MainConfigNames::UploadDirectory );
				$backend = new FSFileBackend( [
					'name'           => 'dumps-backend',
					'wikiId'         => WikiMap::getCurrentWikiId(),
					'lockManager'    => new NullLockManager( [] ),
					'containerPaths' => [ 'dumps-backup' => $dirConfig ?: "{$uploadDir}/dumps" ],
					'fileMode'       => 0777,
					'obResetFunc'    => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ StreamFile::class, 'contentTypeFromPath' ]
				] );
			}

			return $backend;
		}
	}
}
