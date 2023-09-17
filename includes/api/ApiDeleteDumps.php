<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiDeleteDumps extends ApiBase {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$dataDumpConfig = $config->get( 'DataDump' );

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

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

		// @phan-suppress-next-line PhanDeprecatedFunction Only for MW 1.39 or lower.
		if ( $user->isBlockedGlobally() ) {
			// @phan-suppress-next-line PhanDeprecatedFunction Only for MW 1.39 or lower.
			$this->dieBlocked( $user->getGlobalBlock() );
		}

		$this->checkUserRightsAny( $perm, $user );

		$this->doDelete( $type, $fileName );

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doDelete( string $type, string $fileName ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dataDumpConfig = $config->get( 'DataDump' );

		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$row = $dbw->selectRow( 'data_dump', 'dumps_filename', [ 'dumps_filename' => $fileName ] );

		if ( !$row ) {
			$this->dieWithError( [ 'datadump-dump-does-not-exist', $fileName ] );
		} elseif ( $row->dumps_status !== 'completed' || $row->dumps_status !== 'failed' ) {
			$this->dieWithError( [ 'datadump-cannot-delete' ] );
		}

		$backend = DataDump::getBackend();
		$fileBackend = $backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName;

		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [ 'src' => $fileBackend ] );
			if ( $delete->isOK() ) {
				$this->onDeleteDump( $dbw, $fileName );
			} else {
				$this->dieWithError( 'datadump-delete-failed' );
			}
		} else {
			$this->onDeleteDump( $dbw, $fileName );
		}

		return true;
	}

	private function onDeleteDump( $dbw, $fileName ) {
		$dbw->delete(
			'data_dump', [
				'dumps_filename' => $fileName
			],
			__METHOD__
		);

		$logEntry = new ManualLogEntry( 'datadump', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( Title::newFromText( 'Special:DataDump' ) );
		$logEntry->setComment( 'Deleted dumps' );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'type' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'filename' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		return [
			'action=deletedumps&type=example&filename=example_name&token=123ABC'
				=> 'apihelp-deletedumps-example',
		];
	}
}
