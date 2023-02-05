<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\DiscordNotifications\DiscordNotificationsSender;
use MediaWiki\MediaWikiServices;

return [
	'DiscordNotificationsSender' => static function ( MediaWikiServices $services ): ImportDumpRequestManager {
		return new DiscordNotificationsSender(
			RequestContext::getMain(),
			new ServiceOptions(
				DiscordNotificationsSender::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DiscordNotifications' )
			),
			$services->getPermissionManager()
		);
	},
];
