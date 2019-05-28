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

		$params = $this->extractRequestParams();

		$type = $params['type'];

		if ( !$wgDataDump ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		if ( !$wgDataDump[$type] ) {
			$this->dieWithError( [ 'datadump-wrong-type', $type ] );
		}

		$this->checkUserRightsAny( $wgDataDump[$type]['permissions']['view'] );

		$buildWhichArray = [
			'dumps_type' => $type
		];

		if ( isset( $params['filename'] ) && $params['filename'] ) {
			$buildWhichArray['dumps_filename'] = $params['filename'];
		}

		if ( isset( $params['timestamp'] ) && $params['timestamp'] ) {
			$buildWhichArray['dumps_timestamp'] = $params['timestamp'];
		}

		$dumpData = wfGetDB( DB_MASTER )->select(
			'data_dump',
			'*',
			$buildWhichArray,
		);

		if ( $dumpData ) {
			$buildResults = [];
			foreach ( $dumpData as $dump ) {
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
					'/download/' . $dump->dumps_filename;
				$timestamp = $dump->dumps_timestamp ?: '';
				$buildResults[] = [
					'filename' => $dump->dumps_filename,
					'link' => $url,
					'time' => $timestamp,
				];
				/*$this->getResult()->addValue( null, $this->getModuleName(), [
					'filename' => $dump->dumps_filename,
					'link' => $url,
					'time' => $timestamp,
				] );*/
			}
			$this->getResult()->addValue( null, $this->getModuleName(), $buildResults );
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
