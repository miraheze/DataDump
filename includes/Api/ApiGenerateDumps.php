<?php

namespace Miraheze\DataDump\Api;

use ApiBase;
use ApiMain;
use JobSpecification;
use ManualLogEntry;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\Jobs\DataDumpGenerateJob;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;

class ApiGenerateDumps extends ApiBase {

	private IConnectionProvider $connectionProvider;
	private JobQueueGroupFactory $jobQueueGroupFactory;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		IConnectionProvider $connectionProvider,
		JobQueueGroupFactory $jobQueueGroupFactory
	) {
		parent::__construct( $mainModule, $moduleName );

		$this->connectionProvider = $connectionProvider;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
	}

	public function execute(): void {
		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );
		$this->useTransactionalTimeLimit();

		$params = $this->extractRequestParams();
		$type = $params['type'];

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		} elseif ( !isset( $dataDumpConfig[$type] ) ) {
			$this->dieWithError( 'datadump-type-invalid' );
		}

		$perm = $dataDumpConfig[$type]['permissions']['generate'] ?? 'generate-dump';
		$user = $this->getUser();

		if ( $user->getBlock() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->dieBlocked( $user->getBlock() );
		}

		$this->checkUserRightsAny( $perm );
		$this->doGenerate( $type );

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doGenerate( string $type ): void {
		$dataDumpDisableGenerate = $this->getConfig()->get( 'DataDumpDisableGenerate' );
		if ( $dataDumpDisableGenerate ) {
			return;
		}

		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );
		$dbName = $this->getConfig()->get( MainConfigNames::DBname );

		if ( $this->getGenerateLimit( $type ) ) {
			$fileName = $dbName . '_' . $type . '_' .
				bin2hex( random_bytes( 10 ) ) .
					$dataDumpConfig[$type]['file_ending'];

			$dbw = $this->connectionProvider->getPrimaryDatabase();
			$dbw->newInsertQueryBuilder()
				->insertInto( 'data_dump' )
				->row( [
					'dumps_status' => 'queued',
					'dumps_filename' => $fileName,
					'dumps_timestamp' => $dbw->timestamp(),
					'dumps_type' => $type,
				] )
				->caller( __METHOD__ )
				->execute();

			$logEntry = new ManualLogEntry( 'datadump', 'generate' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( SpecialPage::getTitleValueFor( 'DataDump' ) );
			$logEntry->setComment( 'Generated dump' );
			$logEntry->setParameters( [ '4::filename' => $fileName ] );
			$logEntry->publish( $logEntry->insert() );

			$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
			$jobQueueGroup->push(
				new JobSpecification(
					DataDumpGenerateJob::JOB_NAME,
					[
						'arguments' => [],
						'fileName' => $fileName,
						'type' => $type,
					]
				)
			);
		}
	}

	private function getGenerateLimit( string $type ): bool {
		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );

		if ( isset( $dataDumpConfig[$type]['limit'] ) && $dataDumpConfig[$type]['limit'] ) {
			$dbr = $this->connectionProvider->getReplicaDatabase();
			$row = $dbr->newSelectQueryBuilder()
				->select( '*' )
				->from( 'data_dump' )
				->where( [ 'dumps_type' => $type ] )
				->caller( __METHOD__ )
				->fetchRow();

			$limit = $dataDumpConfig[$type]['limit'];

			if ( (int)$row < $limit ) {
				return true;
			} else {
				return false;
			}
		}

		return true;
	}

	/** @inheritDoc */
	public function mustBePosted(): bool {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode(): bool {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'type' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	public function needsToken(): string {
		return 'csrf';
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=generatedumps&type=example&token=123ABC'
				=> 'apihelp-generatedumps-example',
		];
	}
}
