<?php

namespace Miraheze\DataDump;

use FileBackend;
use FSFileBackend;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\StreamFile;
use MediaWiki\WikiMap\WikiMap;
use NullLockManager;

class DataDump {

	public static function getBackend(): FileBackend {
		$services = MediaWikiServices::getInstance();
		$config = $services->getConfigFactory()->makeConfig( 'DataDump' );

		$fileBackend = $config->get( ConfigNames::FileBackend );
		if ( $fileBackend ) {
			return $services->getFileBackendGroup()->get( $fileBackend );
		}

		static $backend = null;
		if ( !$backend ) {
			$dirConfig = $config->get( ConfigNames::Directory );
			$uploadDir = $config->get( MainConfigNames::UploadDirectory );
			$backend = new FSFileBackend( [
				'name' => 'dumps-backend',
				'wikiId' => WikiMap::getCurrentWikiId(),
				'lockManager' => new NullLockManager( [] ),
				'containerPaths' => [ 'dumps-backup' => $dirConfig ?: "{$uploadDir}/dumps" ],
				'fileMode' => 0777,
				'obResetFunc' => 'wfResetOutputBuffers',
				'streamMimeFunc' => [ StreamFile::class, 'contentTypeFromPath' ],
			] );
		}

		return $backend;
	}
}
