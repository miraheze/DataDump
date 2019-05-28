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

		if ( !$wgDataDump ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$buildWhichArray = [
			'dumps_type' => $type
		];

		if ( isset( $params['type'] ) && $params['type'] ) {
			$this->checkUserRightsAny( $wgDataDump[$params['type']]['permissions']['view'] );

			$buildWhichArray['dumps_type'] = $params['type'];
		}

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
			$user = $this->getUser();
			foreach ( $dumpData as $dump ) {
				$dType = $wgDataDump[$dump->dumps_type]['permissions']['view'];
				if ( !$user->isAllowedAny( $dType ) ) {
					continue;
				}
				$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
					'/download/' . $dump->dumps_filename;
				$timestamp = $dump->dumps_timestamp ?: '';
				$buildResults[] = [
					'filename' => $dump->dumps_filename,
					'link' => $url,
					'time' => $timestamp,
				];
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
			'action=viewdumps'
				=> 'apihelp-viewdumps',
			'action=viewdumps&type=xml'
				=> 'apihelp-viewdumps-type-xml',
		];
	}
}
