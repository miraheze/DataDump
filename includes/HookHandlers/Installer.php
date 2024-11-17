<?php

namespace Miraheze\DataDump\HookHandlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use Miraheze\DataDump\Maintenance\MigrateCompletedAndFailedToStatusColumn;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../sql';

		$updater->addExtensionTable( 'data_dump', "$dir/data_dump.sql" );

		$updater->addExtensionField(
			'data_dump',
			'dumps_timestamp',
			"$dir/patches/patch-dumps_timestamp.sql"
		);

		$updater->addExtensionField(
			'data_dump', 'dumps_size',
			"$dir/patches/patch-dumps_size.sql"
		);

		$updater->modifyExtensionTable( 'data_dump', "$dir/patches/patch-dumps_size-bigint.sql" );

		$updater->addExtensionField(
			'data_dump', 'dumps_status',
			"$dir/patches/patch-dumps_status.sql"
		);

		$updater->addExtensionUpdate( [
			'runMaintenance', MigrateCompletedAndFailedToStatusColumn::class,
			'extensions/DataDump/maintenance/MigrateCompletedAndFailedToStatusColumn.php'
		] );

		$updater->dropExtensionField(
			'data_dump', 'dumps_completed',
			"$dir/patches/patch-drop-dumps_completed.sql"
		);

		$updater->dropExtensionField(
			'data_dump', 'dumps_failed',
			"$dir/patches/patch-drop-dumps_failed.sql"
		);
	}
}
