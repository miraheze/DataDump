<?php

use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Stores functions for hooks
 *
 * @author Paladox
 */

class DataDumpHooks implements LoadExtensionSchemaUpdatesHook, SidebarBeforeOutputHook {
	public function onLoadExtensionSchemaUpdates( $updater ) {
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

		$updater->modifyExtensionTable( 'data_dump',
				__DIR__ . '/../sql/patches/patch-dumps_size-bigint.sql' );
	}

	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		if ( isset( $sidebar['managewiki-sidebar-header'] ) ) {
			$sidebar['managewiki-sidebar-header'][] = [
				'text' => wfMessage( 'datadump-link' )->text(),
				'id' => 'datadumplink',
				'href' => htmlspecialchars( SpecialPage::getTitleFor( 'DataDump' )->getFullURL() )
			];
		}
	}
}
