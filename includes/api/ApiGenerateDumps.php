<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiGenerateDumps extends ApiBase {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );
		$dataDumpConfig = $config->get( 'DataDump' );

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$this->useTransactionalTimeLimit();

		$params = $this->extractRequestParams();

		$type = $params['type'];

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		} elseif ( !isset( $dataDumpConfig[$type] ) ) {
			$this->dieWithError( 'datadump-type-invalid' );
		}

		$perm = $dataDumpConfig[$type]['permissions']['generate'] ?? 'generate-dump';
		$user = $this->getUser();

		if ( $user->getBlock() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->dieBlocked( $user->getBlock() );
		} elseif ( $user->isBlockedGlobally() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->dieBlocked( $user->getGlobalBlock() );
		}

		$this->checkUserRightsAny( $perm, $user );

		$this->doGenerate( $type );

		$this->getResult()->addValue( null, $this->getModuleName(), $params );
	}

	private function doGenerate( string $type ) {
		$params = $this->extractRequestParams();
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dataDumpDisableGenerate = $config->get( 'DataDumpDisableGenerate' );
		if ( $dataDumpDisableGenerate ) {
			return true;
		}

		$dataDumpConfig = $config->get( 'DataDump' );
		$dbName = $config->get( 'DBname' );

		if ( $this->getGenerateLimit( $type ) ) {
			$fileName = $dbName . '_' . $type . '_' .
				bin2hex( random_bytes( 10 ) ) .
					$dataDumpConfig[$type]['file_ending'];

			$dbw = MediaWikiServices::getInstance()
				->getDBLoadBalancer()
				->getMaintenanceConnectionRef( DB_PRIMARY );

			$dbw->insert(
				'data_dump', [
					'dumps_status' => 'queued',
					'dumps_filename' => $fileName,
					'dumps_timestamp' => $dbw->timestamp(),
					'dumps_type' => $type
				],
				__METHOD__
			);

			$logEntry = new ManualLogEntry( 'datadump', 'generate' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( Title::newFromText( 'Special:DataDump' ) );
			$logEntry->setComment( 'Generated dump' );
			$logEntry->setParameters( [ '4::filename' => $fileName ] );
			$logEntry->publish( $logEntry->insert() );

			$jobParams = [
				'fileName' => $fileName,
				'type' => $type,
				'arguments' => []
			];

			$job = new DataDumpGenerateJob(
				Title::newFromText( 'Special:DataDump' ), $jobParams );
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
		}

		return true;
	}

	private function getGenerateLimit( string $type ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'datadump' );

		$dataDumpConfig = $config->get( 'DataDump' );

		if ( isset( $dataDumpConfig[$type]['limit'] ) && $dataDumpConfig[$type]['limit'] ) {
			$dbw = MediaWikiServices::getInstance()
				->getDBLoadBalancer()
				->getMaintenanceConnectionRef( DB_PRIMARY );

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
				ParamValidator::PARAM_TYPE => 'string',
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
