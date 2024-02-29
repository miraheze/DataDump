<?php

namespace Miraheze\DataDump\Hooks\Handlers;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Miraheze\DataDump\Maintenance\MigrateCompletedAndFailedToStatusColumn;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../..';

		$updater->addExtensionTable( 'data_dump', "$dir/sql/data_dump.sql" );

		$updater->addExtensionField(
			'data_dump',
			'dumps_timestamp',
			"$dir/sql/patches/patch-dumps_timestamp.sql"
		);

		$updater->addExtensionField(
			'data_dump', 'dumps_size',
			"$dir/sql/patches/patch-dumps_size.sql"
		);

		$updater->modifyExtensionTable( 'data_dump', "$dir/sql/patches/patch-dumps_size-bigint.sql" );

		$updater->addExtensionField(
			'data_dump', 'dumps_status',
			"$dir/sql/patches/patch-dumps_status.sql"
		);

		$updater->addExtensionUpdate( [
			'runMaintenance', MigrateCompletedAndFailedToStatusColumn::class,
			"$dir/maintenance/migrateCompletedAndFailedToStatusColumn.php"
		] );

		$updater->dropExtensionField(
			'data_dump', 'dumps_completed',
			"$dir/sql/patches/patch-drop-dumps_completed.sql"
		);

		$updater->dropExtensionField(
			'data_dump', 'dumps_failed',
			"$dir/sql/patches/patch-drop-dumps_failed.sql"
		);
	}
}
