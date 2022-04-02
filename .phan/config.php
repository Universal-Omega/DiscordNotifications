<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/Flow',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/Flow',
	]
);

$cfg['suppress_issue_types'] = [
	// https://github.com/phan/phan/issues/3420
	'PhanAccessMethodInternal',
];

return $cfg;
