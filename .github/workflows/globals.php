<?php

$wgDiscordIncomingWebhookUrl = 'https://discord.com/api/webhooks/1078075149741477938/1-pOhyjsdrCHgC-G7RDvL0FHHy' . 'POsLt8wq169jW3L7VK9padVkPWq5Q9lp2CDBqsnGyK';
$wgDiscordNotificationWikiUrl = 'http://localhost/';

$wgDiscordNotificationCentralAuthWikiUrl = 'http://localhost/';

// We don't care if this is public since its not for a real active server
$wgDiscordExperimentalWebhook = 'https://discord.com/api/webhooks/1078075188580720810/WeTY7r2qQ08XitYURvdP' . 'iTJ3aQAikqQPvk_Crb60xP24TB5UYMyJdFg9HDg7CYo8oktu';
$wgDiscordEnableExperimentalCVTFeatures = true;
$wgDiscordExperimentalFeedLanguageCode = 'en';

$wgDiscordExperimentalCVTMatchFilter = [
	'test',
];

$wgDiscordExperimentalCVTUsernameFilter = [
	'keywords' => [
		'user-',
	],
];

$wgDiscordExcludeConditions = [
	'experimental' => [
		'article_inserted' => [
			'groups' => [
				'sysop',
			],
		],
	],
];
