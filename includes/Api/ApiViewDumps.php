<?php

namespace Miraheze\DataDump\Api;

use ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use Wikimedia\ParamValidator\ParamValidator;

class ApiViewDumps extends ApiBase {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'DataDump' );
		$dataDumpConfig = $config->get( 'DataDump' );

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$params = $this->extractRequestParams();

		$user = $this->getUser();

		if ( $user->getBlock() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->dieBlocked( $user->getBlock() );
		}

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

		$dumpData = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY )->select(
				'data_dump',
				'*',
				$buildWhichArray
			);

		$buildResults = [];
		if ( $dumpData ) {
			$user = $this->getUser();
			foreach ( $dumpData as $dump ) {
				$perm = $dataDumpConfig[$dump->dumps_type]['permissions']['view'] ?? 'view-dump';

				if ( !$permissionManager->userHasRight( $user, $perm ) ) {
					continue;
				}

				$buildResults[] = [
					'filename' => $dump->dumps_filename,
					'link' => $this->getDownloadUrl( $config, $dump ),
					'time' => $dump->dumps_timestamp ?: '',
					'type' => $dump->dumps_type,
					'status' => $dump->dumps_status,
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

		$title = SpecialPage::getTitleFor( 'DataDump' );

		$query = [
			'action' => 'download',
			'dump' => $dump->dumps_filename,
		];

		return $title->getFullURL( $query );
	}

	public function getAllowedParams() {
		return [
			'type' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'filename' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'timestamp' => [
				ParamValidator::PARAM_TYPE => 'integer',
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
