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

		$dataDumpConfig = $config->get( 'DataDump' );

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$perm = $dataDumpConfig[$type]['permissions']['delete'] ?? 'delete-dump';
		$user = $this->getUser();

		if ( $user->getBlock() || $user->getGlobalBlock() || !$permissionManager->userHasRight( $user, $perm ) ) {
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

		if ( !$dbw->selectRow( 'data_dump', 'dumps_filename', [ 'dumps_filename' => $fileName ] ) ) {
			return;
		}

		$backend = DataDump::getBackend();
		$fileBackend = $backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName;

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
		$logEntry = new ManualLogEntry( 'datadump', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( Title::newFromText( 'Special:DataDump' ) );
		$logEntry->setComment( 'Deleted dumps' );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );

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
