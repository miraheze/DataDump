<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;

/**
 * Special Page for users to generate there own wiki dump e.g xml dump, image dump.
 *
 * Primarily made for wiki farms.
 */
class SpecialDataDump extends SpecialPage {
	/** @var Config */
	private $config;

	/** @var PermissionManager */
	private $permissionManager;

	public function __construct() {
		parent::__construct( 'DataDump', 'view-dump' );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$this->permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$this->checkPermissions();

		$out = $this->getOutput();

		$request = $this->getRequest();

		$user = $this->getUser();

		$dataDumpConfig = $this->config->get( 'DataDump' );
		if ( !$dataDumpConfig ) {
			$out->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		if ( $user->getBlock() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			throw new UserBlockedError( $user->getBlock() );
		} elseif ( $user->getGlobalBlock() ) {
			throw new UserBlockedError( $user->getGlobalBlock() );
		}

		$out->addWikiMsg( 'datadump-desc' );

		$dataDumpInfo = $this->config->get( 'DataDumpInfo' );
		if ( $dataDumpInfo != '' ) {
			$out->addWikiMsg( $dataDumpInfo );
		}

		$action = $request->getVal( 'action' );
		if ( $action ) {
			$dump = $request->getVal( 'dump' );
			$type = $request->getVal( 'type' );

			if ( $action === 'download' && $dump ) {
				$this->doDownload( $dump );
			} elseif ( $action === 'delete' && $type && $dump ) {
				if ( $this->getContext()->getCsrfTokenSet()->matchTokenField( 'token' ) ) {
					$this->doDelete( $type, $dump );
				} else {
					$out->addWikiMsg( 'sessionfailure' );
				}
			}
		}

		$pager = new DataDumpPager( $this->getContext(), $this->getPageTitle() );

		$out->addModuleStyles( [ 'mediawiki.special' ] );

		$pager->getForm();
		$out->addParserOutputContent( $pager->getFullOutput() );
	}

	private function doDownload( string $fileName ) {
		$out = $this->getOutput();
		$out->disable();

		$backend = DataDump::getBackend();
		$backend->streamFile( [
			'src'     =>
				$backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName,
			'headers' => [
				'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT',
				'Cache-Control: no-cache, no-store, max-age=0, must-revalidate',
				'Pragma: no-cache',
				'Content-Disposition: attachment; filename="' . $fileName . '"',
			]
		] )->isOK();

		return true;
	}

	private function doDelete( string $type, string $fileName ) {
		$dataDumpConfig = $this->config->get( 'DataDump' );

		if ( !isset( $dataDumpConfig[$type] ) ) {
			return 'Invalid dump type, or the config is configured wrong';
		}

		$perm = $dataDumpConfig[$type]['permissions']['delete'] ?? 'delete-dump';
		$user = $this->getUser();
		if ( $user->getBlock() || $user->getGlobalBlock() || !$this->permissionManager->userHasRight( $user, $perm ) ) {
			throw new PermissionsError( $perm );
		}

		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		if ( !$dbw->selectRow( 'data_dump', 'dumps_filename', [ 'dumps_filename' => $fileName ] ) ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'datadump-dump-does-not-exist', $fileName )->escaped() )
			);
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
		$logEntry = new ManualLogEntry( 'datadump', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle() );
		$logEntry->setComment( 'Deleted dumps' );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );

		$dbw->delete(
			'data_dump',
			[
				'dumps_filename' => $fileName
			],
			__METHOD__
		);

		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'datadump-delete-success' )->escaped() )
		);
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
			Html::errorBox( $this->msg( 'datadump-delete-failed' )->escaped() )
		);
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
