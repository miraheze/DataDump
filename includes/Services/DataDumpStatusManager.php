<?php

namespace Miraheze\DataDump\Services;

use ManualLogEntry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IConnectionProvider;

class DataDumpStatusManager {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	public function setStatus(
		string $status,
		string $fileName,
		string $fname,
		string $comment,
		int $fileSize,
	): bool {
		$logAction = match ( $status ) {
			'in-progress' => 'generate-in-progress',
			'completed' => 'generate-completed',
			'failed' => 'generate-failed',
		};

		if ( $status === 'in-progress' ) {
			$this->updateDatabase(
				fields: [ 'dumps_status' => $status ],
				fileName: $fileName,
				fname: $fname,
			);
		} elseif ( $status === 'completed' || $status === 'failed' ) {
			if ( file_exists( wfTempDir() . "/$fileName" ) ) {
				unlink( wfTempDir() . "/$fileName" );
			}

			$this->updateDatabase(
				fields: [
					'dumps_status' => $status,
					'dumps_size' => $fileSize,
				],
				fileName: $fileName,
				fname: $fname,
			);
		}

		$logEntry = new ManualLogEntry( 'datadump', $logAction );
		$logEntry->setPerformer( User::newSystemUser(
			User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ]
		) );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'DataDump' ) );
		$logEntry->setComment( $comment );
		$logEntry->setParameters( [ '4::filename' => $fileName ] );
		$logEntry->publish( $logEntry->insert() );

		return $status === 'completed';
	}

	public function getStatus( string $fileName ): string|false {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		return $dbr->newSelectQueryBuilder()
			->select( 'dumps_status' )
			->from( 'data_dump' )
			->where( [ 'dumps_filename' => $fileName ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function updateDatabase(
		array $fields,
		string $fileName,
		string $fname,
	): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( 'data_dump' )
			->set( $fields )
			->where( [ 'dumps_filename' => $fileName ] )
			->caller( $fname )
			->execute();

		$dbw->commit( __METHOD__, 'flush' );
	}

}
