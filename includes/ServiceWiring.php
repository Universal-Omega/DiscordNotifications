<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\DiscordNotifications\DiscordNotifier;
use MediaWiki\MediaWikiServices;

return [
	'DiscordNotifier' => static function ( MediaWikiServices $services ): DiscordNotifier {
		return new DiscordNotifier(
			$services->getHttpRequestFactory(),
			RequestContext::getMain(),
			new ServiceOptions(
				DiscordNotifier::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DiscordNotifications' )
			),
			$services->getPermissionManager()
		);
	},
];
