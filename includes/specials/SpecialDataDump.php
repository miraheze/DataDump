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
		parent::__construct( 'DataDump' );

                $this->db = wfGetDB( DB_MASTER );
	}

	public function execute( $par ) {
		global $wgDataDump;

		$out = $this->getOutput();
		$this->setHeaders();

		if ( !$wgDataDump ) {
			$this->getOutput()->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		if ( !is_null( $par ) && $par !== '' ) {
			$par = explode( '/', $par );

                        $this->type = $par[0];

			if ( $this->type && isset( $par[1] ) ) {
				$this->doDownload( $par );
			} else {
				$this->showDumpForm( $this->type );
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

		$formDescriptor = [
			'dumptype' => [
				'label-message' => 'datadump-label-dumptype',
				'type' => 'select',
				'options' => $options,
				'required' => true,
				'name' => 'dumptype',
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForm' );
		$htmlForm->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmitRedirectToDumpTypeForm' ] )
			->prepareForm()
			->show();

		$formDescriptor = [
			'generate_dump' => [
				'label-message' => 'datadump-label-generate',
				'type' => 'select',
				'options' => $options,
				'required' => true,
				'name' => 'dumptype',
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForm' );
		$htmlForm->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onGenerate' ] )
			->prepareForm()
			->show();

		return true;
	}

	private function onSubmitRedirectToDumpTypeForm( array $params ) {
		if ( $params['dumptype'] !== '' ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() . '/' . $params['dumptype'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}

	private function doDownload( array $par ) {
		$out = $this->getOutput();
		$out->disable();

		$backend = $this->getBackend( $par[0] );

		$file = $backend->getRootStoragePath() . '/dumps-backup/';

		$backend->streamFile( [
			'src'     => $file . $par[1],
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
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() . '/' .
					$type . '/' . $dump->dumps_filename;
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
		global $IP, $wgDBname;
          
                Hooks::run( 'DataDumpDeletion', [ $formData, $form, $fileNames ] );

		/*exec( "/usr/bin/php " .
		     "$IP/maintenance/dumpBackup.php --wiki $wgDBname --full > /mnt/mediawiki-static/private/dumps-test/$this->type/hellow2.txt.xml" );

		$fileName = "{$wgDBname}_{$this->type}_" . rand();
		$this->db->insert( 'data_dump',
			[
				'dumps_completed' => 0,
				'dumps_filename' => $fileName,
				'dumps_failed' => 0,
				'dumps_type' => $this->type
			],
			__METHOD__
		);*/
		$jobParams = [
			'action' => 'delete',
			'fileNames' => $fileNames,
			'data' => $formData,
		];

		$job = new DataDumpJob( Title::newFromText( 'Special:DataDump' ), $jobParams);
		JobQueueGroup::singleton()->push( $job );

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'managewiki-success' )->escaped() . '</div>' );

		//return true;
	}

	/**
	 * @return FileBackend
	 */
	private function getBackend( $type ) {
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
