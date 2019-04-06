<?php

/**
 * Stores functions for hooks
 *
 * @author Paladox
 */
class DataDumpHooks {

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'data_dump', __DIR__ . '/../sql/data_dump.sql' );
	}
}
