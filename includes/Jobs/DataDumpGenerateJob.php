<?php

namespace Miraheze\DataDump\Jobs;

use Job;
use JobSpecification;
use MediaWiki\Config\Config;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use Miraheze\DataDump\ConfigNames;
use Miraheze\DataDump\Services\DataDumpFileBackend;
use Miraheze\DataDump\Services\DataDumpStatusManager;
use MWExceptionHandler;
use RuntimeException;

class DataDumpGenerateJob extends Job {

	public const JOB_NAME = 'DataDumpGenerateJob';

	private readonly array $arguments;
	private readonly string $fileName;
	private readonly string $type;

	public function __construct(
		array $params,
		private readonly DataDumpStatusManager $statusManager,
		private readonly Config $config,
		private readonly DataDumpFileBackend $fileBackend,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->arguments = $params['arguments'];
		$this->fileName = $params['fileName'];
		$this->type = $params['type'];
	}

	public function run(): bool {
		$status = $this->statusManager->getStatus( $this->fileName );
		if ( $status === 'completed' || $status === false ) {
			// Don't rerun a job that is already completed, or if it doesn't exist.
			return true;
		}

		$dataDumpConfig = $this->config->get( ConfigNames::DataDump );
		$dataDumpLimits = $this->config->get( ConfigNames::Limits );
		$dbName = $this->config->get( MainConfigNames::DBname );

		$fileName = $this->fileName;
		$type = $this->type;

		$this->statusManager->setStatus(
			status: 'in-progress',
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
			);
		}

		$exitCodeComment = $dataDumpConfig[$type][
			'logFailedExitCodeComments'
		][$result] ?? '';

		$statusComment = $exitCodeComment ?:
			"Something went wrong: Command exited with {$result}";

		return $this->setStatusViaJob(
			status: 'failed',
			fileName: $fileName,
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
			);
		}

		$fileSize = $this->fileBackend->getBackend()->getFileSize( [
			'src' => "$directoryBackend/$fileName",
		] );

		return $this->setStatusViaJob(
			status: 'completed',
			fileName: $fileName,
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
	): bool {
		$startChunkSize = $config[$type]['startChunkSize'] ?? 0;
		$chunkSize = $config[$type]['chunkSize'] ?? 0;

		if ( $startChunkSize > 0 && $chunkSize > 0 && $fileSize > $startChunkSize ) {
			$backend = $this->fileBackend->getBackend();
			$handle = fopen( $filePath, 'rb' );

			if ( !$handle ) {
				return $this->setStatusViaJob(
					status: 'failed',
					fileName: $fileName,
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
				return $this->setStatusViaJob(
					status: 'failed',
					fileName: $fileName,
					comment: 'Chunking error',
					fileSize: 0
				);
			} finally {
				fclose( $handle );
			}

			return $this->setStatusViaJob(
				status: 'completed',
				fileName: $fileName,
				comment: '',
				fileSize: $fileSize
			);
		}

		return $this->storeFullFile(
			filePath: $filePath,
			directoryBackend: $directoryBackend,
			fileName: $fileName,
		);
	}

	private function storeFullFile(
		string $filePath,
		string $directoryBackend,
		string $fileName,
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
			return $this->setStatusViaJob(
				status: 'completed',
				fileName: $fileName,
				comment: '',
				fileSize: $fileSize
			);
		}

		return $this->setStatusViaJob(
			status: 'failed',
			fileName: $fileName,
			comment: 'Storage error',
			fileSize: 0
		);
	}

	private function setStatusViaJob(
		string $status,
		string $fileName,
		string $comment,
		int $fileSize,
	): bool {
		$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
		$jobQueueGroup->push(
			new JobSpecification(
				DataDumpStatusUpdateJob::JOB_NAME,
				[
					'status' => $status,
					'fileName' => $fileName,
					'comment' => $comment,
					'fileSize' => $fileSize,
				]
			)
		);
		return $status === 'completed';
	}

	/**
	 * @inheritDoc
	 */
	public function allowRetries() {
		return $this->config->get( ConfigNames::AllowRetries );
	}
}
