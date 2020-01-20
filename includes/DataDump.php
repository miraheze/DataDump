<?php

/**
 * Stores shared code to use in multiple places.
 *
 * @author Paladox
 */
class DataDump {
	public static function getDataDumpConfig( string $name ) {
		$config = MediaWiki\MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		return $config->get( $name );
	}

	/**
	 * @return FileBackend
	 */
	public static function getBackend() {
		$fileBackend = self::getDataDumpConfig( 'DataDumpFileBackend' );
		if ( $fileBackend ) {
			return FileBackendGroup::singleton()->get( $fileBackend );
		} else {
			static $backend = null;
			if ( !$backend ) {
				$backend = new FSFileBackend( [
					'name'           => 'dumps-backend',
					'wikiId'         => wfWikiID(),
					'lockManager'    => new NullLockManager( [] ),
					'containerPaths' => [ 'dumps-backup' => self::getDataDumpConfig( 'DataDumpDirectory' ) ],
					'fileMode'       => 777,
					'obResetFunc'    => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ]
				] );
			}
			return $backend;
		}
	}
}
