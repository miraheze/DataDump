<?php

use MediaWiki\MediaWikiServices;

class DataDumpPager extends TablePager {
	private $pageTitle;

	public function __construct( IContextSource $context, $pageTitle ) {
		$this->setContext( $context );

		$this->mDb = wfGetDB( DB_MASTER );

		if ( $this->getRequest()->getText( 'sort', 'dumps_date' ) == 'dumps_date' ) {
			$this->mDefaultDirection = IndexPager::DIR_DESCENDING;
		} else {
			$this->mDefaultDirection = IndexPager::DIR_ASCENDING;
		}

		parent::__construct( $context );

		$this->pageTitle = $pageTitle;
	}

	public function getFieldNames() {
		static $headers = null;

		$headers = [
			'dumps_timestamp' => 'listfiles_date',
			'dumps_filename'  => 'datadump-table-header-name',
			'dumps_type'      => 'datadump-table-header-type',
			'dumps_size'      => 'datadump-table-header-size',
			'dumps_status'    => 'datadump-table-header-status',
			'dumps_delete'    => 'datadump-table-header-delete',
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'dumps_timestamp':
				$time = isset( $row->dumps_timestamp ) ? $row->dumps_timestamp : '';
				$formatted = htmlspecialchars(
					$this->getLanguage()->userTimeAndDate( $time, $this->getUser() )
				);
				break;
			case 'dumps_type':
				$formatted = htmlspecialchars( $row->dumps_type );
				break;
			case 'dumps_filename';
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
						'/download/' . $row->dumps_filename;
				$formatted = Linker::makeExternalLink( $url, $row->dumps_filename );
				break;
			case 'dumps_status':
				if ( (int)$row->dumps_completed === 1 ) {
					$formatted = wfMessage( 'datadump-table-column-ready' )->text();
				} elseif ( (int)$row->dumps_failed === 1 ) {
					$formatted = wfMessage( 'datadump-table-column-failed' )->text();
				} else {
					$formatted = wfMessage( 'datadump-table-column-queued' )->text();
				}
				break;
			case 'dumps_size':
				$formatted = htmlspecialchars(
					$this->getLanguage()->formatSize( isset( $row->dumps_size ) ? $row->dumps_size : 0 ) );
				break;
			case 'dumps_delete':
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
					"/delete/{$row->dumps_type}/{$row->dumps_filename}";
				$formatted = Linker::makeExternalLink( $url, wfMessage( 'datadump-delete-button' )->text() );
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	public function getQueryInfo() {
		return [
			'tables' => [ 'data_dump' ],
			'fields' => [ 'dumps_completed', 'dumps_failed', 'dumps_filename', 'dumps_size', 'dumps_timestamp', 'dumps_type' ],
			'conds' => [],
			'joins_conds' => [],
		];
	}

	public function getDefaultSort() {
		return 'dumps_timestamp';
	}

	public function isFieldSortable( $name ) {
		return true;
	}

	public function getForm() {
		$config = DataDump::getDataDumpConfig( 'DataDump' );

		$opts = [];

		$user = $this->getContext()->getUser();
		$mwPerm = MediaWiki\MediaWikiServices::getInstance()->getPermissionManager();
		foreach ( $config as $name => $value ) {
			$perm = $config[$name]['permissions']['generate'] ?? 'generate-dump';
			if ( $mwPerm->userHasRight( $user, $perm ) ) {
				$opts[$name] = $name;
			}
		}

		if ( !$opts ) {
			return;
		}

		$formDescriptor = [
			'generatedump' => [
				'type' => 'select',
				'label-message' => 'datadump-label-generate',
				'options' => $opts,
				'name' => 'generatedumptype',
				'required' => true,
			],
		];

		$htmlFormGenerate = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForms' );
		$htmlFormGenerate->setMethod( 'post' )
			->setFormIdentifier( 'generateDumpForm' )
			->setSubmitCallback( [ $this, 'onGenerate' ] )
			->prepareForm()
			->show();
	}

	public function onGenerate( array $params ) {
		$dataDump = DataDump::getDataDumpConfig( 'DataDump' );
		$dbName = DataDump::getDataDumpConfig( 'DBname' );

		$type = $params['generatedump'];
		if ( !is_null( $type ) && $type !== '' ) {

			$user = $this->getContext()->getUser();

			$mwPerm = MediaWiki\MediaWikiServices::getInstance()->getPermissionManager();
			$perm = $dataDump[$type]['permissions']['generate'];
			if ( !$mwPerm->userHasRight( $user, $perm) ) {
				throw new PermissionsError( $perm );
			}

			if ( $this->getGenerateLimit( $type ) ) {
				$fileName = $dbName . '_' . $type . '_' .
					bin2hex( random_bytes( 10 ) ) .
						$dataDump[$type]['file_ending'];
				$this->mDb->insert(
					'data_dump',
					[
						'dumps_completed' => 0,
						'dumps_failed' => 0,
						'dumps_filename' => $fileName,
						'dumps_timestamp' => $this->mDb->timestamp(),
						'dumps_type' => $type
					],
					__METHOD__
				);

				$logEntry = new ManualLogEntry( 'datadump', 'generate' );
				$logEntry->setPerformer( $user );
				$logEntry->setTarget( $this->pageTitle );
				$logEntry->setComment( 'Generated dump' );
				$logEntry->setParameters( [ '4::filename' => $fileName ] );
				$logEntry->publish( $logEntry->insert() );

				$jobParams = [
					'fileName' => $fileName,
					'type' => $type,
				];

				$job = new DataDumpGenerateJob(
					Title::newFromText( 'Special:DataDump' ), $jobParams );
				JobQueueGroup::singleton()->push( $job );

				$this->getOutput()->addHTML(
					'<div class="successbox">' . wfMessage( 'datadump-generated-success' )->escaped() . '</div>'
				);
			}
		} else {
			return 'Invalid url.';
		}

		return true;
	}

	public function getGenerateLimit( string $type ) {
		$dataDump = DataDump::getDataDumpConfig( 'DataDump' );

		if ( isset( $dataDump[$type]['limit'] ) && $dataDump[$type]['limit'] ) {
			$db = wfGetDB( DB_MASTER );
			$row = $db->selectRow(
				'data_dump',
				'*',
				[
					'dumps_type' => $type
				]
			);

			$limit = $dataDump[$type]['limit'];

			if ( (int)$row < $limit ) {
				return true;
			} else {
				$this->getOutput()->addHTML(
					'<div class="errorbox">' .
					wfMessage( 'datadump-generated-error', $limit )->escaped() .
					'</div>'
				);

				return false;
			}
		}

		return true;
	}
}
