<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['suppress_issue_types'] = [
	'SecurityCheck-LikelyFalsePositive',
	// Different versions of MediaWiki will need different suppressions.
	'UnusedPluginSuppression'
];

return $cfg;
