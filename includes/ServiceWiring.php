<?php

namespace Miraheze\DataDump;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\DataDump\Services\DataDumpFileBackend;

return [
	'DataDumpConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'DataDump' );
	},
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
