<?php

/**
 * Special Page for users to generate there own wiki dump e.g xml dump, image dump.
 *
 * Primarily made for wiki farms.
 *
 * @author Paladox
 */
class SpecialDataDump extends SpecialPage {

	public $type;
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

			if ( $par[0] === 'generate' && isset( $par[1] ) ) {
				$this->generateDump( $par[1] );
			} else if ( $par[0] === 'download' && isset( $par[1] ) ) {
				$this->doDownload( $par );
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
			}

			$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'datadump-generated-success' )->escaped() . '</div>' );
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

	private function doDownload( array $par ) {
		$out = $this->getOutput();
		$out->disable();

		$backend = self::getBackend();

		$file = $backend->getRootStoragePath() . '/dumps-backup/' . $par[1];

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
		$buildDump = [];
		$fileNames = [];

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
					'default' => "<a href=\"$url\">$dump->dumps_filename</a>",
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
				'label-message' => 'incidentreporting-log-no-data'
			];
		}

		$buildDump += [
			'submit' => [
				'type' => 'submit',
				'default' => wfMessage( 'htmlform-submit' )->text()
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $buildDump, $this->getContext(), 'changeForm' );
		$htmlForm->setMethod( 'post' )
			->setFormIdentifier( 'wikiForm' )
			->setSubmitCallback(
				function ( array $formData, HTMLForm $form ) use ( $fileNames ) {
					return $this->onDeleteInput( $formData, $form, $fileNames );
				}
			)
			->suppressDefaultSubmit()
			->prepareForm()
			->show();
	}

	public function onDeleteInput( array $formData, HTMLForm $form, array $fileNames ) {
          
		$jobParams = [
			'fileNames' => $fileNames,
			'data' => $formData,
		];

		$job = new DataDumpDeleteJob( Title::newFromText( 'Special:DataDump' ), $jobParams);
		JobQueueGroup::singleton()->push( $job );

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'managewiki-success' )->escaped() . '</div>' );

		return true;
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
