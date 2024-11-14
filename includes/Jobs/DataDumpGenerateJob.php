<?php

namespace Miraheze\DataDump\Jobs;

use Job;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Miraheze\DataDump\DataDump;
use RuntimeException;

class DataDumpGenerateJob extends Job {

	/** @var Config */
	private $config;

	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpGenerateJob', $title, $params );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'DataDump' );
	}

	private function log( UserIdentity $user, string $action, string $fileName, ?string $comment = null ) {
		$logEntry = new ManualLogEntry( 'datadump', $action );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( Title::newFromText( 'Special:DataDump' ) );

		if ( $comment ) {
			$logEntry->setComment( $comment );
		}

		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );
	}

	public function run() {
		$dataDumpConfig = $this->config->get( 'DataDump' );
		$dataDumpLimits = $this->config->get( 'DataDumpLimits' );
		$dbName = $this->config->get( MainConfigNames::DBname );

		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		$fileName = $this->params['fileName'];
		$type = $this->params['type'];

		$this->setStatus( 'in-progress', $dbw, '', $fileName, __METHOD__ );

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

		if ( ( $dataDumpConfig[$type]['generate']['type'] ?? '' ) === 'mwscript' ) {
			$generate = array_merge(
				$dataDumpConfig[$type]['generate']['options'],
				$this->params['arguments'] ?? [],
				[ '--wiki', $dbName ]
			);

			$result = Shell::makeScriptCommand(
				$dataDumpConfig[$type]['generate']['script'] ?? '',
				$generate
			)->limits( $dataDumpLimits )
				->disableSandbox()
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

			$result = Shell::command( $command )
				->limits( $dataDumpLimits )
				->disableSandbox()
				->includeStderr()
				->execute()
				->getExitCode();
		}

		/**
		 * The script returning 0 indicates success anything else indicates failures.
		 */
		if ( !$result ) {
			$filePath = wfTempDir() . '/' . $fileName;
			$fileSize = filesize( $filePath );

			if ( $dataDumpConfig[$type]['useBackendTempStore'] ?? false ) {
				// 5GB in bytes
				// if ( $fileSize > 5 * 1024 * 1024 * 1024 ) {
				if ( $fileSize > 10 ) {
					// 1GB in bytes
					// $chunkSize = 1 * 1024 * 1024 * 1024;
					$chunkSize = 1 * 1024 * 1024;
					$handle = fopen( $filePath, 'rb' );
					if ( $handle === false ) {
						throw new RuntimeException( "Could not open file for reading: $filePath" );
					}

					$chunkIndex = 0;
					try {
						while ( !feof( $handle ) ) {
							$chunkFileName = $fileName . '.part' . $chunkIndex;
							$chunkData = fread( $handle, $chunkSize );
							if ( $chunkData === false ) {
								throw new RuntimeException( "Error reading chunk data from file: $filePath" );
							}

							$status = $backend->quickCreate( [
								'content' => $chunkData,
								'dst' => $directoryBackend . '/' . $chunkFileName,
							] );

							if ( !$status->isOK() ) {
								throw new RuntimeException( "Failed to store chunk $chunkIndex: " . $status->getMessage() );
							}

							$chunkIndex++;
						}
					} finally {
						fclose( $handle );
					}

					return $this->setStatus( 'completed', $dbw, $directoryBackend, $fileName, __METHOD__, $fileSize );
				} else {
					// Store the entire file if it is less than 5GB
					$status = $backend->quickStore( [
						'src' => $filePath,
						'dst' => $directoryBackend . '/' . $fileName,
					] );

					if ( $status->isOK() ) {
						return $this->setStatus( 'completed', $dbw, $directoryBackend, $fileName, __METHOD__ );
					}
				}
			} else {
				return $this->setStatus( 'completed', $dbw, $directoryBackend, $fileName, __METHOD__ );
			}
		}

		return $this->setStatus( 'failed', $dbw, $directoryBackend, $fileName, __METHOD__, $result ?? 'Something went wrong' );
	}

	private function setStatus(
		string $status,
		$dbw,
		string $directoryBackend,
		string $fileName,
		string $fname,
		?int $size = null,
		?string $comment = null
	) {
		if ( $status === 'in-progress' ) {
			$dbw->update(
				'data_dump',
				[
					'dumps_status' => 'in-progress',
				],
				[
					'dumps_filename' => $fileName,
				],
				$fname
			);
			$dbw->commit( __METHOD__, 'flush' );
			$this->log( User::newSystemUser( 'Maintenance script' ), 'generate-in-progress', $fileName );

		} elseif ( $status === 'completed' ) {
			if ( file_exists( wfTempDir() . '/' . $fileName ) ) {
				// And now we remove the file from the temp directory, if it exists
				unlink( wfTempDir() . '/' . $fileName );
			}

			$backend = DataDump::getBackend();
			$size ??= $backend->getFileSize( [ 'src' => $directoryBackend . '/' . $fileName ] );
			$dbw->update(
				'data_dump',
				[
					'dumps_status' => 'completed',
					'dumps_size' => $size ?: 0,
				],
				[
					'dumps_filename' => $fileName,
				],
				$fname
			);
			$dbw->commit( __METHOD__, 'flush' );
			$this->log( User::newSystemUser( 'Maintenance script' ), 'generate-completed', $fileName );

		} elseif ( $status === 'failed' ) {
			if ( file_exists( wfTempDir() . '/' . $fileName ) ) {
				// If the file somehow exists in the temp directory,
				// but the command failed, we still want to delete it
				unlink( wfTempDir() . '/' . $fileName );
			}

			$dbw->update(
				'data_dump',
				[
					'dumps_status' => 'failed',
				],
				[
					'dumps_filename' => $fileName,
				],
				$fname
			);
			$dbw->commit( __METHOD__, 'flush' );

			$this->log( User::newSystemUser( 'Maintenance script' ), 'generate-failed', $fileName, 'Failed with the following error:' . $comment );
		}

		return true;
	}
}
