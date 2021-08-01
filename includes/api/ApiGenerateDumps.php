<?php

use MediaWiki\MediaWikiServices;

class ApiGenerateDumps extends ApiBase {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$this->useTransactionalTimeLimit();

		$params = $this->extractRequestParams();

		$type = $params['type'];

		$dataDumpConfig = $config->get( 'DataDump' );

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$perm = $dataDumpConfig[$type]['permissions']['generate'] ?? 'generate-dump';
		if ( !$permissionManager->userHasRight( $this->getUser(), $perm ) ) {
			return;
		}

		$this->doGenerate();

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doGenerate() {
		$params = $this->extractRequestParams();
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dataDumpDisableGenerate = $config->get( 'DataDumpDisableGenerate' );
		if ( $dataDumpDisableGenerate ) {
			return true;
		}

		$dataDumpConfig = $config->get( 'DataDump' );
		$dbName = $config->get( 'DBname' );

		$type = $params['type'];
		if ( !is_null( $type ) && $type !== '' ) {
			if ( $this->getGenerateLimit( $type ) ) {
				$fileName = $dbName . '_' . $type . '_' .
					bin2hex( random_bytes( 10 ) ) .
						$dataDumpConfig[$type]['file_ending'];

				$dbw = wfGetDB( DB_PRIMARY );

				$dbw->insert(
					'data_dump', [
						'dumps_completed' => 0,
						'dumps_failed' => 0,
						'dumps_filename' => $fileName,
						'dumps_timestamp' => $dbw->timestamp(),
						'dumps_type' => $type
					],
					__METHOD__
				);


				$jobParams = [
					'fileName' => $fileName,
					'type' => $type,
					'arguments' => []
				];

				$job = new DataDumpGenerateJob(
					Title::newFromText( 'Special:DataDump' ), $jobParams );
				JobQueueGroup::singleton()->push( $job );
			}
		} else {
			return 'Invalid type.';
		}

		return true;
	}

	private function getGenerateLimit( string $type ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dataDumpConfig = $config->get( 'DataDump' );

		if ( isset( $dataDumpConfig[$type]['limit'] ) && $dataDumpConfig[$type]['limit'] ) {
			$dbw = wfGetDB( DB_PRIMARY );

			$row = $dbw->selectRow(
				'data_dump',
				'*', [
					'dumps_type' => $type
				]
			);

			$limit = $dataDumpConfig[$type]['limit'];

			if ( (int)$row < $limit ) {
				return true;
			} else {
				return false;
			}
		}

		return true;
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'type' => [
				ApiBase::PARAM_TYPE => 'string',
			],
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		return [
			'action=generatedumps&type=example&token=123ABC'
				=> 'apihelp-generatedumps-example',
		];
	}
}
