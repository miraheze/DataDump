<?php

namespace Miraheze\DataDump\Api;

use ApiBase;
use ApiMain;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use stdClass;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;

class ApiViewDumps extends ApiBase {

	private IConnectionProvider $connectionProvider;
	private PermissionManager $permissionManager;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		IConnectionProvider $connectionProvider,
		PermissionManager $permissionManager
	) {
		parent::__construct( $mainModule, $moduleName );

		$this->connectionProvider = $connectionProvider;
		$this->permissionManager = $permissionManager;
	}

	public function execute(): void {
		$dataDumpConfig = $this->getConfig()->get( 'DataDump' );

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

		$dbr = $this->connectionProvider->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'data_dump' )
			->where( $buildWhichArray )
			->caller( __METHOD__ )
			->fetchResultSet();

		$buildResults = [];
		if ( $res ) {
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
