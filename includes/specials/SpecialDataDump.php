<?php

use MediaWiki\Logger\LoggerFactory;

/**
 * Special Page for users to generate there own wiki dump e.g xml dump, image dump.
 *
 * Primarily made for wiki farms.
 *
 * @author Paladox
 */
class SpecialDataDump extends SpecialPage {

	/**
	 * @var Psr\Log\LoggerInterface
	 */
	protected $logger;

	public function __construct() {
		parent::__construct( 'DataDump' );
	}

	/**
	 * @param string $subpage
	 */
	public function execute( $subpage ) {
		global $wgDataDump;

		$this->setHeaders();
		$this->logger = LoggerFactory::getInstance( 'DataDump' );

		if ( !$wgDataDump ) {
			$this->getOutput()->addWikiMsg( 'datadump-not-configured' );
			return;
		}

		if ( $subpage ) {
			$parts = explode( '/', $subpage, 2 );

			if ( count( $parts ) == 1 ) {
				$parts[] = false;
			}

			list( $type ) = $parts;
		} else {
			$type = $this->getRequest()->getVal( 'dumptype' );
		}

		if ( !$type ) {
			$this->showDumpTypeSelector();
			return;
		}

		$this->doDownload( $type );
	}

	protected function showDumpTypeSelector() {
		global $wgDataDump;
 

		$out = $this->getOutput();
		$out->addWikiMsg( 'datadump-choose-type' );
		$html =
			Xml::openElement( 'form', [
				'action' => $this->getPageTitle()->getLocalURL(),
				'method' => 'GET' ] );
		$options = [];
		$selected = 0;

		foreach ( $wgDataDump as $name => $value ) {
			$options[] = [ 'data' => $name, 'label' => $name ];
			$selected++;
		}

		$out->addHTML( $html );
		$out->enableOOUI();
		$out->addHTML(
			new OOUI\ActionFieldLayout(
				new OOUI\DropdownInputWidget( [
					'id' => 'mw-datadump-selector',
					'infusable' => true,
					'options' => $options,
					// 'value' => $wgDataDump,
					'name' => 'dumptype',
				] ),
				new OOUI\ButtonInputWidget( [
					'label' => $this->msg( 'datadump-submit-type' )->text(),
					'type' => 'submit',
					'flags' => [ 'primary', 'progressive' ],
				] ),
				[
					'align' => 'top'
				]
			) .
			Xml::closeElement( 'form' ) . "\n"
		);
	}

	/**
	 * @param string $type
	 */
	protected function doDownload( $type ) {
		$out = $this->getOutput();

		$pager = new DataDumpPager( $type );
		$table = $pager->getBody();

		$out->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}

	protected function getGroupName() {
		return 'developer';
	}

}