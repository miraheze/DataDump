<?php

class SpecialDataDump extends SpecialPage {
	function __construct() {
		parent::__construct( 'DataDump' );
	}

	function execute( $par ) {
		global $wgDataDump;

		$out = $this->getOutput();
		$this->setHeaders();

		if ( !$wgDataDump ) {
			$this->getOutput()->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		$par = explode( '/', $par );
		if ( !is_null( $par ) && $par !== '' ) {
                        //var_dump ( 'test ' . $par );
			$this->showDumpForm( $par[0] );
		} else {
			$this->showSelectBox();
		}
	}

	function showSelectBox() {
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

		return true;
	}

	function onSubmitRedirectToDumpTypeForm( array $params ) {
		if ( $params['dumptype'] !== '' ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() . '/' . $params['dumptype'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}

	function showDumpForm( $type ) {
		$buildLog = [];
		$dbw = wfGetDB( DB_MASTER );
		$dumpData = $dbw->select(
			'data_dump',
			'*',
			[
				'dumps_type' => $type
			]
		);

		if ( $dumpData ) {
			foreach ( $dumpData as $dump ) {
				$buildLog[$dump->dumps_filename] = [
					'type' => 'info',
					'raw' => true,
					'default' => "<a href=\"$dump->dumps_filename\">$dump->dumps_filename</a>",
				];
			}
		} else {
			$buildLog[] = [
				'type' => 'info',
				'label-message' => 'incidentreporting-log-no-data'
			];
		}
		$buildLog += [
			'submit' => [
				'type' => 'submit',
				'default' => wfMessage( 'htmlform-submit' )->text()
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $buildLog, $this->getContext(), 'changeForm' );
		$htmlForm->setMethod( 'post' )
			->setFormIdentifier( 'wikiForm' )
			->setSubmitCallback( [ $this, 'onSubmitInput' ] )
			->suppressDefaultSubmit()
			->prepareForm()
			->show();
	}

	function onSubmitInput( array $params ) {

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'managewiki-success' )->escaped() . '</div>' );

		return true;
	}

	protected function getGroupName() {
		return 'developer';
	}
}