<?php

/**
 * API module to view all data dumps
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiViewDumps extends ApiBase {

	public function execute() {
		global $wgDataDump;

		if ( !$wgDataDump ) {
			$this->dieWithError( [ 'datadumpnotconfigured', wfEscapeWikitext( $globalUser->getName() ) ] );
		}

		$params = $this->extractRequestParams();

        $type = $wgDataDump[$params['type']['permissions']['view']];
		$this->checkUserRightsAny( $type );

		$dumpData = wfGetDB( DB_MASTER )->select(
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
				$language = $this->getLanguage();
				$timestamp = $dump->dumps_timestamp ?
					$language->timeanddate(  $dump->dumps_timestamp ) : '';
				$this->getResult()->addValue( null, $this->getModuleName(), [
					'filename' => $dumpData->dumps_filename,
					'link' => Linker::makeExternalLink( $url, $dump->dumps_filename ),
					'time' => $timestamp,
				] );
			}
		} else {
			$this->dieWithError( [ 'datadumpempty', wfMessage( 'datadump-empty' )->text() ] );
		}
	}

	public function getAllowedParams() {
		return [
			'type' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			],
		];
	}
	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=viewdumps&type=xml'
				=> 'apihelp-viewdumps-type-xml',
		];
	}

	/*public function needsToken() {
		return 'csrf';
	}*/
}
