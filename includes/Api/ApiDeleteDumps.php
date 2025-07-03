<?php

namespace Miraheze\DataDump\Api;

use ManualLogEntry;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\ConfigNames;
use Miraheze\DataDump\Services\DataDumpFileBackend;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class ApiDeleteDumps extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly IConnectionProvider $connectionProvider,
		private readonly DataDumpFileBackend $fileBackend
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$dataDumpConfig = $this->getConfig()->get( ConfigNames::DataDump );
		$this->useTransactionalTimeLimit();

		$params = $this->extractRequestParams();
		$fileName = $params['filename'];
		$type = $params['type'];

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		} elseif ( !isset( $dataDumpConfig[$type] ) ) {
			$this->dieWithError( 'datadump-type-invalid' );
		}

		$perm = $dataDumpConfig[$type]['permissions']['delete'] ?? 'delete-dump';
		$user = $this->getUser();

		$blocked = $user->getBlock();
		if ( $blocked ) {
			$this->dieBlocked( $blocked );
		}

		$this->checkUserRightsAny( $perm );
		$this->doDelete( $fileName );

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doDelete( string $fileName ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$row = $dbw->newSelectQueryBuilder()
			->select( 'dumps_filename' )
			->from( 'data_dump' )
			->where( [ 'dumps_filename' => $fileName ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			$this->dieWithError( [ 'datadump-dump-does-not-exist', $fileName ] );
		} elseif ( $row->dumps_status !== 'completed' || $row->dumps_status !== 'failed' ) {
			$this->dieWithError( [ 'datadump-cannot-delete' ] );
		}

		$this->deleteFileChunks( $fileName );
		$this->onDeleteDump( $fileName, $dbw );
	}

	private function deleteFileChunks( string $fileName ): void {
		$backend = $this->fileBackend->getBackend();
		$fileBackend = $backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName;
		$chunkIndex = 0;

		while ( $backend->fileExists( [ 'src' => $fileBackend . '.part' . $chunkIndex ] ) ) {
			$chunkFileBackend = $fileBackend . '.part' . $chunkIndex;
			$delete = $backend->quickDelete( [ 'src' => $chunkFileBackend ] );
			if ( !$delete->isOK() ) {
				$this->dieWithError( 'datadump-delete-failed' );
			}
			$chunkIndex++;
		}

		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [ 'src' => $fileBackend ] );
			if ( !$delete->isOK() ) {
				$this->dieWithError( 'datadump-delete-failed' );
			}
		}
	}

	private function onDeleteDump( string $fileName, IDatabase $dbw ): void {
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'data_dump' )
			->where( [ 'dumps_filename' => $fileName ] )
			->caller( __METHOD__ )
			->execute();

		$logEntry = new ManualLogEntry( 'datadump', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'DataDump' ) );
		$logEntry->setComment( 'Deleted dumps' );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );
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
			'filename' => [
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
			'action=deletedumps&type=example&filename=example_name&token=123ABC'
				=> 'apihelp-deletedumps-example',
		];
	}
}
