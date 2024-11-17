<?php

namespace Miraheze\DataDump\Specials;

use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\DataDump;
use Miraheze\DataDump\DataDumpPager;
use PermissionsError;
use RuntimeException;
use UserBlockedError;

class SpecialDataDump extends SpecialPage {

	/** @var Config */
	private $config;

	/** @var PermissionManager */
	private $permissionManager;

	public function __construct() {
		parent::__construct( 'DataDump', 'view-dump' );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'DataDump' );
		$this->permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$this->checkPermissions();
		$this->checkReadOnly();

		$out = $this->getOutput();
		$out->addModules( [ 'mediawiki.special.userrights' ] );

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
		}

		if ( !$user->isAllowed( 'generate-dump' ) ) {
			$out->setPageTitle( $this->msg( 'datadump-view' )->escaped() );
			$out->addWikiMsg( 'datadump-view-desc' );
		}

		$dataDumpInfo = $this->config->get( 'DataDumpInfo' );
		if ( $dataDumpInfo != '' ) {
			$out->addWikiMsg( $dataDumpInfo );
		}

		$action = $request->getVal( 'action' );
		$dump = $request->getVal( 'dump' );
		$type = $request->getVal( 'type' );

		if ( $action && $dump ) {
			if ( $action === 'download' ) {
				$this->doDownload( $dump );
			} elseif ( $action === 'delete' && $type ) {
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
		$directoryBackend = $backend->getContainerStoragePath( 'dumps-backup' );

		// Check if the file exists directly or in chunked parts
		if ( $backend->fileExists( [ 'src' => $directoryBackend . '/' . $fileName ] ) ) {
			// Stream the entire file if it exists as a single part
			$backend->streamFile( [
				'src' => $directoryBackend . '/' . $fileName,
				'headers' => [
					'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT',
					'Cache-Control: no-cache, no-store, max-age=0, must-revalidate',
					'Pragma: no-cache',
					'Content-Disposition: attachment; filename="' . $fileName . '"',
				]
			] )->isOK();
		} else {
			// Stream file chunks if they exist
			$chunkIndex = 0;
			$chunkFileName = $fileName . '.part' . $chunkIndex;
			$headersSent = false;

			while ( $backend->fileExists( [ 'src' => $directoryBackend . '/' . $chunkFileName ] ) ) {
				// Send headers only once, when starting to stream the first chunk
				if ( !$headersSent ) {
					header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT' );
					header( 'Cache-Control: no-cache, no-store, max-age=0, must-revalidate' );
					header( 'Pragma: no-cache' );
					header( 'Content-Disposition: attachment; filename="' . $fileName . '"' );
					header( 'Content-Type: application/octet-stream' );
					header( 'Transfer-Encoding: chunked' );
					$headersSent = true;
				}

				// Stream the current chunk
				$backend->streamFile( [
					'src' => $directoryBackend . '/' . $chunkFileName,
					'headless' => true,
				] );

				// Move to the next chunk
				$chunkIndex++;
				$chunkFileName = $fileName . '.part' . $chunkIndex;
			}

			if ( $chunkIndex === 0 ) {
				// No chunks or file were found, so return an error
				throw new RuntimeException( "File not found: $fileName" );
			}
		}

		return true;
	}

	private function doDelete( string $type, string $fileName ) {
		$dataDumpConfig = $this->config->get( 'DataDump' );

		if ( !isset( $dataDumpConfig[$type] ) ) {
			return 'Invalid dump type or the config is configured wrong';
		}

		$user = $this->getUser();
		$perm = $dataDumpConfig[$type]['permissions']['delete'] ?? 'delete-dump';
		if ( !$this->permissionManager->userHasRight( $user, $perm ) ) {
			throw new PermissionsError( $perm );
		}

		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		$fileCheck = $dbw->selectRow( 'data_dump', 'dumps_filename', [ 'dumps_filename' => $fileName ] );

		if ( !$fileCheck ) {
			$this->getOutput()->addHTML(
				Html::warningBox(
					Html::element(
						'p',
						[],
						$this->msg( 'datadump-dump-does-not-exist', $fileName )->text()
					),
					'mw-notify-error'
				)
			);

			return;
		}

		$backend = DataDump::getBackend();
		$fileBackend = $backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName;

		// Delete chunks if the file is chunked
		$chunkIndex = 0;
		while ( $backend->fileExists( [ 'src' => $fileBackend . '.part' . $chunkIndex ] ) ) {
			$chunkFileBackend = $fileBackend . '.part' . $chunkIndex;
			$delete = $backend->quickDelete( [ 'src' => $chunkFileBackend ] );
			if ( !$delete->isOK() ) {
				$this->getOutput()->addHTML(
					Html::warningBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-delete-failed' )->text()
						),
						'mw-notify-error'
					)
				);
				return;
			}
			$chunkIndex++;
		}

		// Now delete the main file if it exists
		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [ 'src' => $fileBackend ] );
			if ( !$delete->isOK() ) {
				$this->getOutput()->addHTML(
					Html::warningBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-delete-failed' )->text()
						),
						'mw-notify-error'
					)
				);
				return;
			}
		}

		// Perform the database cleanup
		$this->onDeleteDump( $dbw, $fileName );

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

		$logEntry = new ManualLogEntry( 'datadump', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle() );
		$logEntry->setComment( 'Deleted dumps' );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );

		$this->getOutput()->addHTML(
			Html::successBox(
				Html::element(
					'p',
					[],
					$this->msg( 'datadump-delete-success' )->text()
				),
				'mw-notify-success'
			)
		);
	}

	public function doesWrites() {
		return true;
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
