<?php

use MediaWiki\MediaWikiServices;

class ApiDeleteDumps extends ApiBase {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$this->useTransactionalTimeLimit();

		$params = $this->extractRequestParams();
	
		$fileName = $params['filename'];
		$type = $params['type'];

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$perm = $dataDumpConfig[$dump->dumps_type]['permissions']['delete'] ?? 'delete-dump';
		if ( !$permissionManager->userHasRight( $this->getUser(), $perm ) ) {
			return;
		}

		$this->doDelete( $type, $fileName );

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doDelete( string $type, string $fileName ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dataDumpConfig = $config->get( 'DataDump' );

		if ( !isset( $dataDumpConfig[$type] ) ) {
			return 'Invalid dump type, or the config is configured wrong';
		}

		$dbw = wfGetDB( DB_PRIMARY );

		if ( !$dbw->selectRow(  'data_dump', 'dumps_filename', [ 'dumps_filename' => $fileName ] ) ) {
			return;
		}

		$backend = DataDump::getBackend();
		$fileBackend = $backend->getRootStoragePath() . "/dumps-backup/{$fileName}";

		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [ 'src' => $fileBackend ] );
			if ( $delete->isOK() ) {
				$this->onDeleteDump( $dbw, $fileName );
			} else {
				$this->onDeleteFailureDump( $dbw, $fileName );
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
	}

	private function onDeleteFailureDump( $dbw, $fileName ) {
		$dbw->update(
			'data_dump', [
				'dumps_failed' => 1
			], [
				'dumps_filename' => $fileName
			],
			__METHOD__
		);
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
				ApiBase::PARAM_TYPE => 'string',
			],
			'filename' => [
				ApiBase::PARAM_TYPE => 'string',
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
