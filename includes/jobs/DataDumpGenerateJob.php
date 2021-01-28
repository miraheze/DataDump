<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

/**
 * Used to generate dump
 *
 * @author Paladox
 */
class DataDumpGenerateJob extends Job {

	private $config = null;

	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpGenerateJob', $title, $params );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
	}

	public function run() {
		$dataDumpConfig = $this->config->get( 'DataDump' );
		$dataDumpLimits = $this->config->get( 'DataDumpLimits' );
		$dbName = $this->config->get( 'DBname' );

		$dbw = wfGetDB( DB_MASTER );

		$fileName = $this->params['fileName'];
		$type = $this->params['type'];

		$options = [];
		foreach ( $dataDumpConfig[$type]['generate']['options'] as $option ) {
			$options[] = preg_replace( '/\$\{filename\}/im', $fileName, $option );
		}

		$config[$type]['generate']['options'] = $options;

		$backend = DataDump::getBackend();
		$directoryBackend = $backend->getRootStoragePath() . '/dumps-backup/';
		if ( !$backend->directoryExists( [ 'dir' => $directoryBackend ] ) ) {
			$backend->prepare( [ 'dir' => $directoryBackend ] );
		}

		if ( $dataDumpConfig[$type]['generate']['type'] === 'mwscript' ) {
			$generate = array_merge(
				$dataDumpConfig[$type]['generate']['options'],
				[ '--wiki', $dbName ]
			);

			$result = Shell::makeScriptCommand(
				$dataDumpConfig[$type]['generate']['script'],
				$generate
			)
				->limits( $dataDumpLimits )
				->includeStderr()
				->execute()
				->getExitCode();
		} else {
			$command = array_merge(
				[
					$dataDumpConfig[$type]['generate']['script']
				],
				$dataDumpConfig[$type]['generate']['options']
			);

			$result = Shell::command( $command )
				->limits( $dataDumpLimits )
				->includeStderr()
				->execute()
				->getExitCode();
		}

		/**
		 * The script returning 0 indicates success anything else indicates failures.
		 */
		if ( !$result ) {
			$size = $backend->getFileSize( [ 'src' => $directoryBackend . $fileName ] );

			$dbw->update(
				'data_dump',
				[
					'dumps_completed' => 1,
					'dumps_failed' => 0,
					'dumps_size' => $size ? $size : 0,
				],
				[
					'dumps_filename' => $fileName
				],
				__METHOD__
			);
		} else {
			$dbw->update(
				'data_dump',
				[
					'dumps_completed' => 0,
					'dumps_failed' => 1
				],
				[
					'dumps_filename' => $fileName
				],
				__METHOD__
			);
		}

		return true;
	}
}
