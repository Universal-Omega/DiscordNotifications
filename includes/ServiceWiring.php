<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\DiscordNotifications\DiscordNotifier;
use MediaWiki\MediaWikiServices;

return [
	'DiscordNotifier' => static function ( MediaWikiServices $services ): DiscordNotifier {
		return new DiscordNotifier(
			new ServiceOptions(
				DiscordNotifier::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DiscordNotifications' )
			),
			$services->getPermissionManager()
		);
	},
];
