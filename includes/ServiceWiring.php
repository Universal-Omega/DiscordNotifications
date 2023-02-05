<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\DiscordNotifications\DiscordEmbedBuilder;
use MediaWiki\Extension\DiscordNotifications\DiscordNotifier;
use MediaWiki\MediaWikiServices;

return [
	'DiscordEmbedBuilder' => static function ( MediaWikiServices $services ): DiscordEmbedBuilder {
		return new DiscordEmbedBuilder();
	},

	'DiscordNotifier' => static function ( MediaWikiServices $services ): DiscordNotifier {
		return new DiscordNotifier(
			$services->getService( 'DiscordEmbedBuilder' ),
			RequestContext::getMain(),
			new ServiceOptions(
				DiscordNotifier::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DiscordNotifications' )
			),
			$services->getPermissionManager()
		);
	},
];
