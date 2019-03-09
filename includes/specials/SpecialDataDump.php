<?php

/**
 * Special Page for users to generate there own wiki dump e.g xml dump, image dump.
 *
 * Primarily made for wiki farms.
 *
 * @author Paladox
 */
class SpecialDataDump extends SpecialPage {

	public $db;

	public function __construct() {
		parent::__construct( 'DataDump', 'view-dump' );

		$this->db = wfGetDB( DB_MASTER );
	}

	public function execute( $par ) {
		global $wgDataDump;

		$out = $this->getOutput();
		$this->setHeaders();

		$this->checkPermissions();

		if ( !$wgDataDump ) {
			$this->getOutput()->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		if ( !is_null( $par ) && $par !== '' ) {
			$par = explode( '/', $par );

			if ( $par[0] === 'download' && isset( $par[1] ) ) {
				$this->doDownload( $par[1] );
			} else if ( $par[0] === 'view' && isset( $par[1] ) ) {
				$this->showDumpForm( $par[1] );
			}
		} else {
			$this->showSelectBox();
		}
	}

	private function showSelectBox() {
		global $wgDataDump;

		$options = [];

		foreach ( $wgDataDump as $name => $value ) {
			$options[$name] = $name;
		}

		$formDescriptorSelect = [
			'dumptype' => [
				'label-message' => 'datadump-label-dumptype',
				'type' => 'select',
				'options' => $options,
				'required' => true,
				'name' => 'dumptype',
			]
		];

		$htmlFormSelect = HTMLForm::factory( 'ooui', $formDescriptorSelect, $this->getContext(), 'searchForm' );
		$htmlFormSelect->setMethod( 'post' )
			->setFormIdentifier( 'selectDumpForm' )
			->setSubmitCallback( [ $this, 'onSubmitRedirectToDumpTypeForm' ] )
			->prepareForm()
			->show();

		$formDescriptorGenerate = [
			'generatedump' => [
				'label-message' => 'datadump-label-generate',
				'type' => 'select',
				'options' => $options,
				'required' => true,
				'name' => 'generatedumptype',
			]
		];

		$htmlFormGenerate = HTMLForm::factory( 'ooui', $formDescriptorGenerate, $this->getContext(), 'searchForms' );
		$htmlFormGenerate->setMethod( 'post' )
			->setFormIdentifier( 'generateDumpForm' )
			->setSubmitCallback( [ $this, 'onGenerate' ] )
			->prepareForm()
			->show();

		return true;
	}

	public function onGenerate( array $params ) {
		global $wgDataDump, $wgDBname;

		$type = $params['generatedump'];
		if ( !is_null( $type ) && $type !== '' ) {

			$perm = $wgDataDump[$type]['permissions']['generate'];
			if ( !$this->getContext()->getUser()->isAllowed( $perm ) ) {
				throw new PermissionsError( $perm );
			}

			if ( $this->getGenerateLimit( $type ) ) {
				$fileName = $wgDBname . "_" . $type . "_" . rand() .
					$wgDataDump[$type]['file_ending'];
				$this->db->insert( 'data_dump',
					[
						'dumps_completed' => 0,
						'dumps_filename' => $fileName,
						'dumps_failed' => 0,
						'dumps_type' => $type
					],
					__METHOD__
				);

				$jobParams = [
					'fileName' => $fileName,
					'type' => $type,
				];

				$job = new DataDumpGenerateJob(
					Title::newFromText( 'Special:DataDump' ), $jobParams );
				JobQueueGroup::singleton()->push( $job );

				$this->getOutput()->addHTML(
					'<div class="successbox">' . wfMessage( 'datadump-generated-success' )->escaped() . '</div>' );
			}
		} else {
			return 'Invalid url.';
		}

		return true;
	}

	public function onSubmitRedirectToDumpTypeForm( array $params ) {
		if ( $params['dumptype'] !== '' ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
				'/view/' . $params['dumptype'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}

	private function doDownload( string $type ) {
		$out = $this->getOutput();
		$out->disable();

		$backend = self::getBackend();

		$file = $backend->getRootStoragePath() . '/dumps-backup/' . $type;

		$backend->streamFile( [
			'src'     => $file,
			'headers' => [
				'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT',
				'Cache-Control: no-cache, no-store, max-age=0, must-revalidate',
				'Pragma: no-cache',
			]
		] )->isOK();

		return true;
	}

	private function showDumpForm( $type ) {
		global $wgDataDump;

		$buildDump = [];
		$fileNames = [];

		$perm = $wgDataDump[$type]['permissions']['view'];
		if ( !$this->getContext()->getUser()->isAllowed( $perm ) ) {
			throw new PermissionsError( $perm );
		}

		$dumpData = $this->db->select(
			'data_dump',
			'*',
			[
				'dumps_type' => $type
			]
		);

		if ( $dumpData ) {
			foreach ( $dumpData as $dump ) {
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
					'/download/' . $dump->dumps_filename;
				$buildDump[$dump->dumps_filename] = [
					'type' => 'info',
					'raw' => true,
					'default' => Linker::makeExternalLink( $url, $dump->dumps_filename ),
				];
				$buildDump["delete_{$dump->dumps_filename}"] = [
					'type' => 'check',
					'default' => false,
				];

				$fileNames[] = $dump->dumps_filename;
			}
		} else {
			$buildDump[] = [
				'type' => 'info',
				'label-message' => 'datadump-no-results'
			];
		}

		$buildDump += [
			'submit' => [
				'type' => 'submit',
				'default' => wfMessage( 'datadump-delete-button' )->text()
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $buildDump, $this->getContext(), 'changeForm' );
		$htmlForm->setMethod( 'post' )
			->setFormIdentifier( 'wikiForm' )
			->setSubmitCallback(
				function ( array $formData, HTMLForm $form ) use ( $type, $fileNames ) {
					return $this->onDeleteInput( $formData, $form, $type, $fileNames );
				}
			)
			->suppressDefaultSubmit()
			->prepareForm()
			->show();
	}

	public function onDeleteInput( array $formData, HTMLForm $form, string $type, array $fileNames ) {
		global $wgDataDump;

		$perm = $wgDataDump[$type]['permissions']['delete'];
		if ( !$this->getContext()->getUser()->isAllowed( $perm ) ) {
			throw new PermissionsError( $perm );
		}

		$jobParams = [
			'fileNames' => $fileNames,
			'data' => $formData,
		];

		$job = new DataDumpDeleteJob( Title::newFromText( 'Special:DataDump' ), $jobParams);
		JobQueueGroup::singleton()->push( $job );

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'datadump-delete-success' )->escaped() . '</div>' );

		return false;
	}

	public function getGenerateLimit( $type ) {
		global $wgDataDump;

		if ( isset( $wgDataDump[$type]['limit'] ) && $wgDataDump[$type]['limit'] ) {
			$row = $this->db->selectRow(
				'data_dump',
				'*',
				[
					'dumps_type' => $type
				]
			);

			if ( $row < $wgDataDump[$type]['limit'] ) {
				return true;
			} else {
				$this->getOutput()->addHTML(
					'<div class="errorbox">' . wfMessage( 'datadump-generated-error' )->escaped() . '</div>' );

				return false;
			}
		}

		return true;
	}

	/**
	 * @return FileBackend
	 */
	public static function getBackend() {
		global $wgDataDumpFileBackend, $wgDataDumpDirectory;
		if ( $wgDataDumpFileBackend ) {
			return FileBackendGroup::singleton()->get( $wgDataDumpFileBackend );
		} else {
			static $backend = null;
			if ( !$backend ) {
				$backend = new FSFileBackend( [
					'name'           => 'dumps-backend',
					'wikiId'         => wfWikiID(),
					'lockManager'    => new NullLockManager( [] ),
					'containerPaths' => [ 'dumps-backup' => $wgDataDumpDirectory ],
					'fileMode'       => 777,
					'obResetFunc'    => 'wfResetOutputBuffers',
					'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ]
				] );
			}
			return $backend;
		}
	}

	protected function getGroupName() {
		return 'developer';
	}
}
