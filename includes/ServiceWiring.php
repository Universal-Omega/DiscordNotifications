<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\DiscordNotifications\DiscordSender;
use MediaWiki\MediaWikiServices;

return [
	'DiscordSender' => static function ( MediaWikiServices $services ): DiscordSender {
		return new DiscordSender(
			RequestContext::getMain(),
			new ServiceOptions(
				DiscordSender::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DiscordNotifications' )
			),
			$services->getPermissionManager()
		);
	},
];
