<?php

namespace Miraheze\DataDump\HookHandlers;

use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\ManageWiki\Hooks\ManageWikiAfterSidebarLinksHook;
use Skin;

class ManageWiki implements ManageWikiAfterSidebarLinksHook {

	/** @inheritDoc */
	public function onManageWikiAfterSidebarLinks( Skin $skin, array &$sidebarLinks ): void {
		$sidebarLinks[] = [
			'text' => $skin->msg( 'datadump-link' )->text(),
			'id' => 'datadumplink',
			'href' => htmlspecialchars( SpecialPage::getTitleFor( 'DataDump' )->getFullURL() ),
		];
	}
}
