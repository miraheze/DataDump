<?php

namespace Miraheze\DataDump\Jobs;

use Job;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use Miraheze\DataDump\ConfigNames;
use Miraheze\DataDump\Services\DataDumpFileBackend;
use MWExceptionHandler;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class DataDumpGenerateJob extends Job {

	public const JOB_NAME = 'DataDumpGenerateJob';

	private readonly array $arguments;
	private readonly string $fileName;
	private readonly string $type;

	public function __construct(
		array $params,
		private readonly IConnectionProvider $connectionProvider,
		private readonly Config $config,
		private readonly DataDumpFileBackend $fileBackend
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->arguments = $params['arguments'];
		$this->fileName = $params['fileName'];
		$this->type = $params['type'];
	}

	public function run(): bool {
		$dataDumpConfig = $this->config->get( ConfigNames::DataDump );
		$dataDumpLimits = $this->config->get( ConfigNames::Limits );
		$dbName = $this->config->get( MainConfigNames::DBname );
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$fileName = $this->fileName;
		$type = $this->type;

		$this->setStatus(
			status: 'in-progress',
			dbw: $dbw,
			fileName: $fileName,
			fname: __METHOD__,
			comment: '',
			fileSize: 0
		);

		$options = array_map(
			static fn ( string $opt ): string => preg_replace(
				'/\$\{filename\}/im', $fileName, $opt
			),
			$dataDumpConfig[$type]['generate']['options'] ?? []
		);

		$dataDumpConfig[$type]['generate']['options'] = $options;

		$backend = $this->fileBackend->getBackend();
		$directoryBackend = $backend->getContainerStoragePath( 'dumps-backup' );

		if ( !$backend->directoryExists( [ 'dir' => $directoryBackend ] ) ) {
			$backend->prepare( [ 'dir' => $directoryBackend ] );
		}

		$result = $this->executeCommand(
			config: $dataDumpConfig,
			limits: $dataDumpLimits,
			type: $type,
			dbName: $dbName
		);

		if ( $result === 0 ) {
			return $this->handleSuccess(
				config: $dataDumpConfig,
				type: $type,
				fileName: $fileName,
				directoryBackend: $directoryBackend,
				dbw: $dbw
			);
		}

		$exitCodeComment = $dataDumpConfig[$type][
			'logFailedExitCodeComments'
		][$result] ?? '';

		$statusComment = $exitCodeComment ?:
			"Something went wrong: Command exited with {$result}";

		return $this->setStatus(
			status: 'failed',
			dbw: $dbw,
			fileName: $fileName,
			fname: __METHOD__,
			comment: $statusComment,
			fileSize: 0
		);
	}

	private function executeCommand(
		array $config,
		array $limits,
		string $type,
		string $dbName
	): int {
		$options = $config[$type]['generate']['options'] ?? [];
		$script = $config[$type]['generate']['script'] ?? '';

		if ( ( $config[$type]['generate']['type'] ?? '' ) === 'mwscript' ) {
			$command = array_merge(
				$options,
				$this->arguments,
				[ '--wiki', $dbName ]
			);

			return Shell::makeScriptCommand( $script, $command )
				->limits( $limits )
				->disableSandbox()
				->includeStderr()
				->execute()
				->getExitCode();
		}

		$command = array_merge( [ $script ], $options );
		return Shell::command( $command )
			->limits( $limits )
			->disableSandbox()
			->includeStderr()
			->execute()
			->getExitCode();
	}

	private function handleSuccess(
		array $config,
		string $type,
		string $fileName,
		string $directoryBackend,
		IDatabase $dbw
	): bool {
		if ( $config[$type]['useBackendTempStore'] ?? false ) {
			$filePath = wfTempDir() . '/' . $fileName;
			$fileSize = filesize( $filePath );
			return $this->storeWithChunking(
				config: $config,
				type: $type,
				filePath: $filePath,
				directoryBackend: $directoryBackend,
				fileName: $fileName,
				fileSize: $fileSize,
				dbw: $dbw
			);
		}

		$fileSize = $this->fileBackend->getBackend()->getFileSize( [
			'src' => "$directoryBackend/$fileName",
		] );

		return $this->setStatus(
			status: 'completed',
			dbw: $dbw,
			fileName: $fileName,
			fname: __METHOD__,
			comment: '',
			fileSize: $fileSize
		);
	}

	private function storeWithChunking(
		array $config,
		string $type,
		string $filePath,
		string $directoryBackend,
		string $fileName,
		int $fileSize,
		IDatabase $dbw
	): bool {
		$startChunkSize = $config[$type]['startChunkSize'] ?? 0;
		$chunkSize = $config[$type]['chunkSize'] ?? 0;

		if ( $startChunkSize > 0 && $chunkSize > 0 && $fileSize > $startChunkSize ) {
			$backend = $this->fileBackend->getBackend();
			$handle = fopen( $filePath, 'rb' );

			if ( !$handle ) {
				return $this->setStatus(
					status: 'failed',
					dbw: $dbw,
					fileName: $fileName,
					fname: __METHOD__,
					comment: 'Could not open file for reading',
					fileSize: 0
				);
			}

			try {
				$chunkIndex = 0;
				while ( !feof( $handle ) ) {
					$chunkData = fread( $handle, $chunkSize );
					if ( $chunkData === false ) {
						throw new RuntimeException( "Error reading chunk data from $filePath" );
					}

					$status = $backend->quickCreate( [
						'content' => $chunkData,
						'dst' => "$directoryBackend/$fileName.part$chunkIndex",
					] );

					if ( !$status->isOK() ) {
						throw new RuntimeException( "Failed to store chunk $chunkIndex" );
					}

					$chunkIndex++;
				}
			} catch ( RuntimeException $ex ) {
				MWExceptionHandler::logException( $ex );
				return $this->setStatus(
					status: 'failed',
					dbw: $dbw,
					fileName: $fileName,
					fname: __METHOD__,
					comment: 'Chunking error',
					fileSize: 0
				);
			} finally {
				fclose( $handle );
			}

			return $this->setStatus(
				status: 'completed',
				dbw: $dbw,
				fileName: $fileName,
				fname: __METHOD__,
				comment: '',
				fileSize: $fileSize
			);
		}

		return $this->storeFullFile(
			filePath: $filePath,
			directoryBackend: $directoryBackend,
			fileName: $fileName,
			dbw: $dbw
		);
	}

	private function storeFullFile(
		string $filePath,
		string $directoryBackend,
		string $fileName,
		IDatabase $dbw
	): bool {
		$backend = $this->fileBackend->getBackend();
		$status = $backend->quickStore( [
			'src' => $filePath,
			'dst' => "$directoryBackend/$fileName",
		] );

		if ( $status->isOK() ) {
			$fileSize = $backend->getFileSize( [
				'src' => "$directoryBackend/$fileName",
			] );
			return $this->setStatus(
				status: 'completed',
				dbw: $dbw,
				fileName: $fileName,
				fname: __METHOD__,
				comment: '',
				fileSize: $fileSize
			);
		}

		return $this->setStatus(
			status: 'failed',
			dbw: $dbw,
			fileName: $fileName,
			fname: __METHOD__,
			comment: 'Storage error',
			fileSize: 0
		);
	}

	private function setStatus(
		string $status,
		string $fileName,
		string $fname,
		string $comment,
		int $fileSize,
		IDatabase $dbw
	): bool {
		$logAction = match ( $status ) {
			'in-progress' => 'generate-in-progress',
			'completed' => 'generate-completed',
			'failed' => 'generate-failed',
		};

		if ( $status === 'in-progress' ) {
			$this->updateDatabase(
				dbw: $dbw,
				fileName: $fileName,
				fname: $fname,
				fields: [ 'dumps_status' => $status ]
			);
		} elseif ( $status === 'completed' || $status === 'failed' ) {
			if ( file_exists( wfTempDir() . "/$fileName" ) ) {
				unlink( wfTempDir() . "/$fileName" );
			}

			$this->updateDatabase(
				dbw: $dbw,
				fileName: $fileName,
				fname: $fname,
				fields: [
					'dumps_status' => $status,
					'dumps_size' => $fileSize,
				]
			);
		}

		$logEntry = new ManualLogEntry( 'datadump', $logAction );
		$logEntry->setPerformer( User::newSystemUser(
			User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ]
		) );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'DataDump' ) );
		$logEntry->setComment( $comment );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );

		return $status === 'completed';
	}

	private function updateDatabase(
		array $fields,
		string $fileName,
		string $fname,
		IDatabase $dbw
	): void {
		$dbw->newUpdateQueryBuilder()
			->update( 'data_dump' )
			->set( $fields )
			->where( [ 'dumps_filename' => $fileName ] )
			->caller( $fname )
			->execute();

		$dbw->commit( __METHOD__, 'flush' );
	}
}
