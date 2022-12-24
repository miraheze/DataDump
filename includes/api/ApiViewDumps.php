<?php

use MediaWiki\MediaWikiServices;

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

		$dumpData = wfGetDB( DB_PRIMARY )->select(
			'data_dump',
			'*',
			$buildWhichArray
		);

		$buildResults = [];
		if ( $dumpData ) {
			foreach ( $dumpData as $dump ) {
				$perm = $dataDumpConfig[$dump->dumps_type]['permissions']['view'] ?? 'view-dump';
				$user = $this->getUser();

				if ( $user->getBlock() || $user->getGlobalBlock() || !$permissionManager->userHasRight( $user, $perm ) ) {
					continue;
				}

				$buildResults[] = [
					'filename' => $dump->dumps_filename,
					'link' => $this->getDownloadUrl( $config, $dump ),
					'time' => $dump->dumps_timestamp ?: '',
					'type' => $dump->dumps_type,
				];
			}
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $buildResults );
	}

	private function getDownloadUrl( $config, $dump ) {
		// Do not create a link if the file has not been created.
		if ( (int)$dump->dumps_completed !== 1 ) {
			return $dump->dumps_filename;
		}

		// If wgDataDumpDownloadUrl is configured, use that
		// rather than using the internal streamer.
		if ( $config->get( 'DataDumpDownloadUrl' ) ) {
			$url = preg_replace(
				'/\$\{filename\}/im',
				$dump->dumps_filename,
				$config->get( 'DataDumpDownloadUrl' )
			);
			return $url;
		}

		$title = SpecialPage::getTitleFor( 'DataDump' );

		$query = [
			'action' => 'download',
			'dump' => $dump->dumps_filename
		];

		return $title->getFullURL( $query );
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
