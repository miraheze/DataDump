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

		$buildWhichArray = [
			'dumps_type' => $type
		];

		if ( isset( $params['filename'] ) && $params['filename'] ) {
			$buildWhichArray['dumps_filename'] = $params['filename'];
		}

		if ( isset( $params['timestamp'] ) && $params['dumps_timestamp'] ) {
			$buildWhichArray['dumps_timestamp'] = $params['dumps_timestamp'];
		}

		$dumpData = wfGetDB( DB_MASTER )->select(
			'data_dump',
			'*',
			$buildWhichArray,
		);

		if ( $dumpData ) {
			foreach ( $dumpData as $dump ) {
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
					'/download/' . $dump->dumps_filename;
				$timestamp = $dump->dumps_timestamp ?: '';
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
			'filename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'timestamp' => [
				ApiBase::PARAM_TYPE => 'integer',
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
}
