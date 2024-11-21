<?php

namespace Miraheze\DataDump\Services;

use FileBackend;
use FileBackendGroup;
use FSFileBackend;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\StreamFile;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\DataDump\ConfigNames;
use NullLockManager;

class DataDumpFileBackend {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Directory,
		ConfigNames::FileBackend,
		MainConfigNames::UploadDirectory,
	];

	private FileBackendGroup $fileBackendGroup;
	private ServiceOptions $options;

	public function __construct(
		FileBackendGroup $fileBackendGroup,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->fileBackendGroup = $fileBackendGroup;
		$this->options = $options;
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
