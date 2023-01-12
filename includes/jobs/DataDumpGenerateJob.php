<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

/**
 * Used to generate dump
 *
 * @phan-file-suppress PhanTypeInvalidDimOffset
 */
class DataDumpGenerateJob extends Job {
	/** @var Config */
	private $config;

	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpGenerateJob', $title, $params );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
	}

	public function run() {
		$dataDumpConfig = $this->config->get( 'DataDump' );
		$dataDumpLimits = $this->config->get( 'DataDumpLimits' );
		$dbName = $this->config->get( 'DBname' );

		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		$fileName = $this->params['fileName'];
		$type = $this->params['type'];

		$options = [];
		foreach ( $dataDumpConfig[$type]['generate']['options'] as $option ) {
			$options[] = preg_replace( '/\$\{filename\}/im', $fileName, $option );
		}

		$dataDumpConfig[$type]['generate']['options'] = $options;

		$backend = DataDump::getBackend();
		$directoryBackend = $backend->getContainerStoragePath( 'dumps-backup' );
		if ( !$backend->directoryExists( [ 'dir' => $directoryBackend ] ) ) {
			$backend->prepare( [ 'dir' => $directoryBackend ] );
		}

		if ( $dataDumpConfig[$type]['generate']['type'] === 'mwscript' ) {
			$generate = array_merge(
				$dataDumpConfig[$type]['generate']['options'],
				$this->params['arguments'] ?? [],
				[ '--wiki', $dbName ]
			);

			// @phan-suppress-next-line PhanDeprecatedFunction
			$result = Shell::makeScriptCommand(
				$dataDumpConfig[$type]['generate']['script'] ?? '',
				$generate
			)
				->limits( $dataDumpLimits )
				->restrict( Shell::RESTRICT_NONE )
				->includeStderr()
				->execute()
				->getExitCode();
		} else {
			$command = array_merge(
				[
					$dataDumpConfig[$type]['generate']['script'] ?? []
				],
				$dataDumpConfig[$type]['generate']['options']
			);

			// @phan-suppress-next-line PhanDeprecatedFunction
			$result = Shell::command( $command )
				->limits( $dataDumpLimits )
				->restrict( Shell::RESTRICT_NONE )
				->includeStderr()
				->execute()
				->getExitCode();
		}

		/**
		 * The script returning 0 indicates success anything else indicates failures.
		 */
		if ( !$result ) {
			if ( $dataDumpConfig[$type]['useBackendTempStore'] ?? false ) {
				$status = $backend->quickStore( [
					'src' => wfTempDir() . '/' . $fileName,
					'dst' => $directoryBackend . '/' . $fileName,
				] );

				if ( !$status->isOK() ) {
					return $this->failed( $dbw, $fileName, __METHOD__ );
				}
			}

			return $this->complete( $dbw, $backend, $directoryBackend, $fileName, __METHOD__ );
		}

		return $this->failed( $dbw, $fileName, __METHOD__ );
	}

	private function complete( $dbw, $backend, $directoryBackend, $fileName, $fname ) {
		if ( file_exists( wfTempDir() . '/' . $fileName ) ) {
			// And now we remove the file from the temp directory, if it exists
			unlink( wfTempDir() . '/' . $fileName );
		}

		$size = $backend->getFileSize( [ 'src' => $directoryBackend . '/' . $fileName ] );
		$dbw->update(
			'data_dump',
			[
				'dumps_completed' => 1,
				'dumps_failed' => 0,
				'dumps_size' => $size ?: 0,
			],
			[
				'dumps_filename' => $fileName,
			],
			$fname
		);

		return true;
	}

	private function failed( $dbw, $fileName, $fname ) {
		if ( file_exists( wfTempDir() . '/' . $fileName ) ) {
			// If the file somehow exists in the temp directory,
			// but the command failed, we still want to delete it
			unlink( wfTempDir() . '/' . $fileName );
		}

		$dbw->update(
			'data_dump',
			[
				'dumps_completed' => 0,
				'dumps_failed' => 1,
			],
			[
				'dumps_filename' => $fileName,
			],
			$fname
		);

		return true;
	}
}
