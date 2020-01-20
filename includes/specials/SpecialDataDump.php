<?php

/**
 * Special Page for users to generate there own wiki dump e.g xml dump, image dump.
 *
 * Primarily made for wiki farms.
 *
 * @author Paladox
 */
class SpecialDataDump extends SpecialPage {

	public function __construct() {
		parent::__construct( 'DataDump', 'view-dump' );
	}

	public function execute( $par ) {

		$this->setHeaders();
		$this->outputHeader();

		$this->checkPermissions();

		$out = $this->getOutput();

		$config = DataDump::getDataDumpConfig( 'DataDump' );
		if ( !$config ) {
			$out->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		$out->addWikiMsg( 'datadump-desc' );

		$info = DataDump::getDataDumpConfig( 'DataDumpInfo' );
		if ( !empty( $info ) && is_string( $info ) ) {
			$out->addWikiMsg( $info );
		}

		if ( !is_null( $par ) && $par !== '' ) {
			$par = explode( '/', $par );

			if ( $par[0] === 'download' && isset( $par[1] ) ) {
				$this->doDownload( $par[1] );
				return;
			} else if ( $par[0] === 'delete' && isset( $par[1] ) && isset( $par[2] ) ) {
				$this->doDelete( $par[1], $par[2] );
				return;
			}
		}

		$pager = new DataDumpPager( $this->getContext(), $this->getPageTitle() );

		$out->addModuleStyles( 'mediawiki.special' );

		$pager->getForm();
		$out->addParserOutputContent( $pager->getFullOutput() );
	}

	private function doDownload( string $fileName ) {
		$out = $this->getOutput();
		$out->disable();

		$backend = DataDump::getBackend();
		$backend->streamFile( [
			'src'     =>
				$backend->getRootStoragePath() . '/dumps-backup/' . $fileName,
			'headers' => [
				'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT',
				'Cache-Control: no-cache, no-store, max-age=0, must-revalidate',
				'Pragma: no-cache',
			]
		] )->isOK();

		return true;
	}

	private function doDelete( string $type, string $fileName ) {
		$dataDump = DataDump::getDataDumpConfig( 'DataDump' );

		if ( !isset( $dataDump[$type] ) ) {
			return 'Invalid dump type, or the config is configured wrong';
		}

		$mwPerm = MediaWiki\MediaWikiServices::getInstance()->getPermissionManager();
		$perm = $dataDump[$type]['permissions']['delete'];
		if ( !$mwPerm->userHasRight( $this->getUser(), $perm) ) {
			throw new PermissionsError( $perm );
		}

		$logEntry = new ManualLogEntry( 'datadump', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle() );
		$logEntry->setComment( 'Deleted dumps' );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );

		$backend = DataDump::getBackend();
		$fileBackend =
			$backend->getRootStoragePath() . '/dumps-backup/' . $fileName;
		$dbw = wfGetDB( DB_MASTER );
		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [
				'src' => $fileBackend,
			] );
			if ( $delete->isOK() ) {
				$this->onDeleteDump( $dbw, $fileName );
			} else {
				$this->onDeleteFailureDump( $dbw, $fileName );
			}
		} else {
			$this->onDeleteDump( $dbw, $fileName );
		}

		header( 'Location: ' . SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() );

		return true;
	}

	private function onDeleteDump( $dbw, $fileName ) {
		$dbw->delete(
			'data_dump',
			[
				'dumps_filename' => $fileName
			],
			__METHOD__
		);

		$this->getOutput()->addHTML(
			'<div class="successbox">' . wfMessage( 'datadump-delete-success' )->escaped() . '</div>' );
	}

	private function onDeleteFailureDump( $dbw, $fileName ) {
		$dbw->update(
			'data_dump',
			[
				'dumps_failed' => 1
			],
			[
				'dumps_filename' => $fileName
			],
			__METHOD__
		);

		$this->getOutput()->addHTML(
			'<div class="errorbox">' . wfMessage( 'datadump-delete-failed' )->escaped() . '</div>' );
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
