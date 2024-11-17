<?php

namespace Miraheze\DataDump\Api;

use ApiBase;
use ApiMain;
use ManualLogEntry;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\DataDump;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class ApiDeleteDumps extends ApiBase {
	
	private IConnectionProvider $connectionProvider;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		IConnectionProvider $connectionProvider
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->connectionProvider = $connectionProvider;
	}

	public function execute(): void {
		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );
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

		if ( $user->getBlock() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->dieBlocked( $user->getBlock() );
		}

		$this->checkUserRightsAny( $perm );
		$this->doDelete( $type, $fileName );

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doDelete( string $type, string $fileName ): void {
		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$row = $dbw->selectRow(
			'data_dump',
			'dumps_filename',
			[
				'dumps_filename' => $fileName,
			],
			__METHOD__
		);

		if ( !$row ) {
			$this->dieWithError( [ 'datadump-dump-does-not-exist', $fileName ] );
		} elseif ( $row->dumps_status !== 'completed' || $row->dumps_status !== 'failed' ) {
			$this->dieWithError( [ 'datadump-cannot-delete' ] );
		}

		$this->deleteFileChunks( $fileName );
		$this->onDeleteDump( $fileName, $dbw );
	}

	private function deleteFileChunks( string $fileName ): void {
		$backend = DataDump::getBackend();
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
		$dbw->delete(
			'data_dump',
			[
				'dumps_filename' => $fileName,
			],
			__METHOD__
		);

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
