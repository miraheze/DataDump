<?php

/**
 * API module to view all data dumps
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiViewDumps extends ApiBase {

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$dataDumpConfig = $config->get( 'DataDump' );

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$params = $this->extractRequestParams();

		$buildWhichArray = [];

		if ( isset( $params['type'] ) && $params['type'] ) {
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
			$buildWhichArray
		);

		$buildResults = [];		
		if ( $dumpData ) {
			foreach ( $dumpData as $dump ) {
				$perm = $dataDumpConfig[$dump->dumps_type]['permissions']['view'] ?? 'view-dump';
				
				if ( !$permissionManager->userHasRight( $this->getUser(), $perm ) ) {
					continue;
				}

				$buildResults[] = [
					'filename' => $dump->dumps_filename,
					'link' => $this->_getDownloadUrl( $config, $dump ),
					'time' => $dump->dumps_timestamp ?: '',
					'type' => $dump->dumps_type,
				];
			}
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $buildResults );
	}

	private function _getDownloadUrl( object $config, object $dump ) {
 		if ( $config->get( 'DataDumpDownloadUrl' ) != '' ) {
 			$url = preg_replace(
 				'/\$\{filename\}/im',
 				$row->dumps_filename,
 				$config->get( 'DataDumpDownloadUrl' )
 			);
 			return Linker::makeExternalLink( $url );
 		}

 		$url = SpecialPage::getTitleFor( 'DataDump' )->getFullUrl() .
 				'/download/' . $dump->dumps_filename;
 		return Linker::makeExternalLink( $url, $dump->dumps_filename );
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
				=> 'apihelp-viewdumps-example-1',
			'action=viewdumps&type=xml'
				=> 'apihelp-viewdumps-example-2',
		];
	}
}
