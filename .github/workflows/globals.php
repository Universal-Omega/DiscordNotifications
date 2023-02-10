<?php

$wgDiscordIncomingWebhookUrl = getenv( 'DISCORD_WEBHOOK' );
$wgDiscordNotificationWikiUrl = 'http://localhost/';

$wgDiscordNotificationCentralAuthWikiUrl = 'http://localhost/';

$wgDiscordExperimentalWebhook = 'https://discord.com/api/webhooks/963568043320041502/cy764BVVAJokojgQEke7XPMm4-vxrp6Coa9R5Buclpq6Hu5EHao1ideCHddNk2z1rPX5';
$wgDiscordEnableExperimentalCVTFeatures = true;

$wgDiscordExperimentalCVTMatchFilter = [
	'test',
];
