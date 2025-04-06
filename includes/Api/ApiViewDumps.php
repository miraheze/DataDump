<?php

namespace Miraheze\DataDump\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\ConfigNames;
use stdClass;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;

class ApiViewDumps extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly IConnectionProvider $connectionProvider,
		private readonly PermissionManager $permissionManager
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$dataDumpConfig = $this->getConfig()->get( ConfigNames::DataDump );

		if ( !$dataDumpConfig ) {
			$this->dieWithError( [ 'datadump-not-configured' ] );
		}

		$params = $this->extractRequestParams();
		$user = $this->getUser();

		$blocked = $user->getBlock();
		if ( $blocked ) {
			$this->dieBlocked( $blocked );
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

		$dbr = $this->connectionProvider->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'data_dump' )
			->where( $buildWhichArray )
			->caller( __METHOD__ )
			->fetchResultSet();

		$buildResults = [];
		if ( $res->numRows() ) {
			$user = $this->getUser();
			foreach ( $res as $row ) {
				$perm = $dataDumpConfig[$row->dumps_type]['permissions']['view'] ?? 'view-dump';

				if ( !$this->permissionManager->userHasRight( $user, $perm ) ) {
					continue;
				}

				$buildResults[] = [
					'filename' => $row->dumps_filename,
					'link' => $this->getDownloadUrl( $row ),
					'time' => $row->dumps_timestamp ?: '',
					'type' => $row->dumps_type,
					'status' => $row->dumps_status,
				];
			}
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $buildResults );
	}

	private function getDownloadUrl( stdClass $row ): string {
		// Do not create a link if the file has not been created.
		if ( $row->dumps_status !== 'completed' ) {
			return $row->dumps_filename;
		}

		$title = SpecialPage::getTitleFor( 'DataDump' );

		$query = [
			'action' => 'download',
			'dump' => $row->dumps_filename,
		];

		return $title->getFullURL( $query );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
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
	protected function getExamplesMessages(): array {
		return [
			'action=viewdumps'
				=> 'apihelp-viewdumps-example-1',
			'action=viewdumps&type=xml'
				=> 'apihelp-viewdumps-example-2',
		];
	}
}
