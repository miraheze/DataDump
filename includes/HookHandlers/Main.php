<?php

namespace Miraheze\DataDump\HookHandlers;

use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\SpecialPage\SpecialPage;
use Skin;

class Main implements SidebarBeforeOutputHook {

	/**
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		if ( isset( $sidebar['managewiki-sidebar-header'] ) ) {
			$sidebar['managewiki-sidebar-header'][] = [
				'text' => $skin->msg( 'datadump-link' )->parse(),
				'id' => 'datadumplink',
				'href' => htmlspecialchars( SpecialPage::getTitleFor( 'DataDump' )->getFullURL() )
			];
		}
	}
}
