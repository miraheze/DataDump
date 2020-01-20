<?php

use MediaWiki\Shell\Shell;

/**
 * Used to generate dump
 *
 * @author Paladox
 */
class DataDumpGenerateJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpGenerateJob', $title, $params );
	}

	public function run() {
		$config = DataDump::getDataDumpConfig( 'DataDump' );
		$limits = DataDump::getDataDumpConfig( 'DataDumpLimits' );
		$dbName = DataDump::getDataDumpConfig( 'DBname' );

		$dbw = wfGetDB( DB_MASTER );

		$fileName = $this->params['fileName'];
		$type = $this->params['type'];

		$options = [];
		foreach ( $config[$type]['generate']['options'] as $option ) {
			$options[] = preg_replace( '/\$\{filename\}/im', $fileName, $option );
		}

		$config[$type]['generate']['options'] = $options;

		$backend = DataDump::getBackend();
		$directoryBackend = $backend->getRootStoragePath() . '/dumps-backup/';
		if ( !$backend->directoryExists( [ 'dir' => $directoryBackend ] ) ) {
			$backend->prepare( [ 'dir' => $directoryBackend ] );
		}

		if ( $config[$type]['generate']['type'] === 'mwscript' ) {
			$generate = array_merge(
				$config[$type]['generate']['options'],
				[ '--wiki', $dbName ]
			);

			$result = Shell::makeScriptCommand(
				$config[$type]['generate']['script'],
				$generate
			)
				->limits( $limits )
				->execute()
				->getExitCode();
		} else {
			$command = array_merge(
				[
					$config[$type]['generate']['script']
				],
				$config[$type]['generate']['options']
			);

			$result = Shell::command( $command )
				->limits( $limits )
				->execute()
				->getExitCode();
		}

		/**
		 * The script returning 0 indicates success anything else indicates failures.
		 */
		if ( $result < 1) {
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
