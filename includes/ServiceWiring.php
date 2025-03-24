<?php

namespace Miraheze\DiscordNotifications;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;

return [
	'DiscordNotifier' => static function ( MediaWikiServices $services ): DiscordNotifier {
		return new DiscordNotifier(
			RequestContext::getMain(),
			new ServiceOptions(
				DiscordNotifier::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'DiscordNotifications' )
			),
			$services->getPermissionManager(),
			$services->getUserGroupManager()
		);
	},
];
