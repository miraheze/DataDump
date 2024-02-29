<?php

namespace Miraheze\DataDump\Hooks\Handlers;

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
				'text' => $skin->msg( 'datadump-link' )->text(),
				'id' => 'datadumplink',
				'href' => htmlspecialchars( SpecialPage::getTitleFor( 'DataDump' )->getFullURL() )
			];
		}
	}
}
