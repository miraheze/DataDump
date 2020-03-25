<?php

/**
 * Stores functions for hooks
 *
 * @author Paladox
 */
class DataDumpHooks {

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable(
			'data_dump',
			__DIR__ . '/../sql/data_dump.sql'
		);

		$updater->addExtensionField(
			'data_dump',
			'dumps_timestamp',
			__DIR__ . '/../sql/patches/patch-dumps_timestamp.sql'
		);

		$updater->addExtensionField(
			'data_dump',
			'dumps_size',
			__DIR__ . '/../sql/patches/patch-dumps_size.sql'
		);
	}

	public static function onNewSidebarItem( $skin, &$bar ) {
		if ( isset( $bar['Administration'] ) && $bar['Administration'] ) {
			$bar['Administration'][] = [
				'text' => wfMessage( "datadump-link" )->plain(),
				'id' => "datadumplink",
				'href' => htmlspecialchars( SpecialPage::getTitleFor( 'DataDump' )->getFullURL() )
			];
		}
	}
}
