<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\DataDump\Services\DataDumpFileBackend;

return [
	'DataDumpFileBackend' => static function ( MediaWikiServices $services ): DataDumpFileBackend {
		return new DataDumpFileBackend(
			$services->getFileBackendGroup(),
			new ServiceOptions(
				DataDumpFileBackend::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DataDump' )
			)
		);
	},
];
