<?php

namespace Miraheze\DataDump\Specials;

use FileBackend;
use ManualLogEntry;
use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\DataDump;
use Miraheze\DataDump\DataDumpPager;
use PermissionsError;
use RuntimeException;
use UserBlockedError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class SpecialDataDump extends SpecialPage {

	private IConnectionProvider $connectionProvider;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	private PermissionManager $permissionManager;

	public function __construct(
		IConnectionProvider $connectionProvider,
		JobQueueGroupFactory $jobQueueGroupFactory,
		PermissionManager $permissionManager
	) {
		parent::__construct( 'DataDump', 'view-dump' );

		$this->connectionProvider = $connectionProvider;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();

		$this->checkPermissions();
		$this->checkReadOnly();

		$out = $this->getOutput();
		$out->addModules( [ 'mediawiki.special.userrights' ] );

		$request = $this->getRequest();
		$user = $this->getUser();

		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );
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

		$dataDumpInfo = $this->getConfig()->get( 'DataDumpInfo' );
		if ( $dataDumpInfo ) {
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

		$pager = new DataDumpPager(
			$this->getConfig(),
			$this->getContext(),
			$this->connectionProvider,
			$this->jobQueueGroupFactory,
			$this->getLinkRenderer(),
			$this->permissionManager
		);

		$out->addModuleStyles( [ 'mediawiki.special' ] );

		$pager->getForm();
		$out->addParserOutputContent( $pager->getFullOutput() );
	}

	private function doDownload( string $fileName ): bool {
		$out = $this->getOutput();
		$out->disable();

		$backend = DataDump::getBackend();
		$directoryBackend = $backend->getContainerStoragePath( 'dumps-backup' );

		if ( $backend->fileExists( [ 'src' => $directoryBackend . '/' . $fileName ] ) ) {
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
			$this->streamFileChunks( $fileName, $directoryBackend, $backend );
		}

		return true;
	}

	private function streamFileChunks(
		string $fileName,
		string $directoryBackend,
		FileBackend $backend
	): void {
		$chunkIndex = 0;
		$chunkFileName = $fileName . '.part' . $chunkIndex;
		$headersSent = false;

		while ( $backend->fileExists( [ 'src' => $directoryBackend . '/' . $chunkFileName ] ) ) {
			if ( !$headersSent ) {
				header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT' );
				header( 'Cache-Control: no-cache, no-store, max-age=0, must-revalidate' );
				header( 'Pragma: no-cache' );
				header( 'Content-Disposition: attachment; filename="' . $fileName . '"' );
				header( 'Content-Type: application/octet-stream' );
				header( 'Transfer-Encoding: chunked' );
				$headersSent = true;
			}

			$backend->streamFile( [
				'src' => $directoryBackend . '/' . $chunkFileName,
				'headless' => true,
			] );

			$chunkIndex++;
			$chunkFileName = $fileName . '.part' . $chunkIndex;
		}

		if ( $chunkIndex === 0 ) {
			throw new RuntimeException( "File not found: $fileName" );
		}
	}

	private function doDelete( string $type, string $fileName ): void {
		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );

		if ( !isset( $dataDumpConfig[$type] ) ) {
			return;
		}

		$user = $this->getUser();
		$perm = $dataDumpConfig[$type]['permissions']['delete'] ?? 'delete-dump';
		if ( !$this->permissionManager->userHasRight( $user, $perm ) ) {
			throw new PermissionsError( $perm );
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$fileCheck = $dbw->selectRow( 'data_dump', 'dumps_filename', [ 'dumps_filename' => $fileName ] );

		if ( !$fileCheck ) {
			$this->getOutput()->addHTML(
				Html::warningBox(
					Html::element( 'p', [], $this->msg( 'datadump-dump-does-not-exist', $fileName )->text() ),
					'mw-notify-error'
				)
			);

			return;
		}

		$this->deleteFileChunks( $fileName, $dbw );
		$this->onDeleteDump( $fileName, $dbw );
	}

	private function deleteFileChunks( string $fileName, IDatabase $dbw ): void {
		$backend = DataDump::getBackend();
		$fileBackend = $backend->getContainerStoragePath( 'dumps-backup' ) . '/' . $fileName;
		$chunkIndex = 0;

		while ( $backend->fileExists( [ 'src' => $fileBackend . '.part' . $chunkIndex ] ) ) {
			$chunkFileBackend = $fileBackend . '.part' . $chunkIndex;
			$delete = $backend->quickDelete( [ 'src' => $chunkFileBackend ] );
			if ( !$delete->isOK() ) {
				$this->getOutput()->addHTML(
					Html::warningBox(
						Html::element( 'p', [], $this->msg( 'datadump-delete-failed' )->text() ),
						'mw-notify-error'
					)
				);
				return;
			}
			$chunkIndex++;
		}

		if ( $backend->fileExists( [ 'src' => $fileBackend ] ) ) {
			$delete = $backend->quickDelete( [ 'src' => $fileBackend ] );
			if ( !$delete->isOK() ) {
				$this->getOutput()->addHTML(
					Html::warningBox(
						Html::element( 'p', [], $this->msg( 'datadump-delete-failed' )->text() ),
						'mw-notify-error'
					)
				);
			}
		}
	}

	private function onDeleteDump( string $fileName, IDatabase $dbw ): void {
		$dbw->delete(
			'data_dump',
			[ 'dumps_filename' => $fileName ],
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
				Html::element( 'p', [], $this->msg( 'datadump-delete-success' )->text() ),
				'mw-notify-success'
			)
		);
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}
}
