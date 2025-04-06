<?php

namespace Miraheze\DataDump\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\FileBackend\FileBackendGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\StreamFile;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\DataDump\ConfigNames;
use NullLockManager;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;

class DataDumpFileBackend {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Directory,
		ConfigNames::FileBackend,
		MainConfigNames::UploadDirectory,
	];

	public function __construct(
		private readonly FileBackendGroup $fileBackendGroup,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function getBackend(): FileBackend {
		$fileBackend = $this->options->get( ConfigNames::FileBackend );
		if ( $fileBackend ) {
			return $this->fileBackendGroup->get( $fileBackend );
		}

		static $backend = null;
		if ( !$backend ) {
			$dirConfig = $this->options->get( ConfigNames::Directory );
			$uploadDir = $this->options->get( MainConfigNames::UploadDirectory );
			$backend = new FSFileBackend( [
				'name' => 'dumps-backend',
				'wikiId' => WikiMap::getCurrentWikiId(),
				'lockManager' => new NullLockManager( [] ),
				'containerPaths' => [ 'dumps-backup' => $dirConfig ?: "{$uploadDir}/dumps" ],
				'fileMode' => 0644,
				'obResetFunc' => 'wfResetOutputBuffers',
				'streamMimeFunc' => [ StreamFile::class, 'contentTypeFromPath' ],
			] );
		}

		return $backend;
	}
}
