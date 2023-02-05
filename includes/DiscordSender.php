<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\DiscordNotifications;

use IContextSource;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Permissions\PermissionManager;

class DiscordSender {

	public const CONSTRUCTOR_OPTIONS = [
		'DiscordAdditionalIncomingWebhookUrls',
		'DiscordAvatarUrl',
		'DiscordCurlProxy',
		'DiscordExcludedPermission',
		'DiscordFromName',
		'DiscordIncomingWebhookUrl',
		'DiscordSendMethod',
		'Sitename',
	];

	/** @var IContextSource */
	private $context;

	/** @var ServiceOptions */
	private $options;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param IContextSource $context
	 * @param ServiceOptions $options
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		IContextSource $context,
		ServiceOptions $options,
		PermissionManager $permissionManager
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->context = $context;
		$this->options = $options;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * Sends the message into Discord.
	 *
	 * @param string $message
	 * @param string $action
	 * @param ?string $webhook
	 */
	public function pushDiscordNotify( string $message, string $action, ?string $webhook = null ) {
		if ( $this->options->get( 'DiscordExcludedPermission' ) ) {
			$user = $this->context->getUser();
			if ( $user && $this->permissionManager->userHasRight( $user, $this->options->get( 'DiscordExcludedPermission' ) ) ) {
				// Users with the permission suppress notifications
				return;
			}
		}

		// Convert " to ' in the message to be sent as otherwise JSON formatting would break.
		$message = str_replace( '"', "'", $message );

		$discordFromName = $this->options->get( 'DiscordFromName' );
		if ( $discordFromName == '' ) {
			$discordFromName = $this->options->get( 'Sitename' );
		}

		$message = preg_replace( '~(<)(http)([^|]*)(\|)([^\>]*)(>)~', '[$5]($2$3)', $message );
		$message = str_replace( [ "\r", "\n" ], '', $message );

		switch ( $action ) {
			case 'article_saved':
				$colour = 2993970;

				break;
			case 'import_complete':
				$colour = 2993970;

				break;
			case 'user_groups_changed':
				$colour = 2993970;

				break;
			case 'article_inserted':
				$colour = 3580392;

				break;
			case 'article_deleted':
				$colour = 15217973;

				break;
			case 'article_moved':
				$colour = 14038504;

				break;
			case 'article_protected':
				$colour = 3493864;

				break;
			case 'new_user_account':
				$colour = 3580392;

				break;
			case 'file_uploaded':
				$colour = 3580392;

				break;
			case 'user_blocked':
				$colour = 15217973;

				break;
			case 'flow':
				$colour = 2993970;

				break;
			default:
				$colour = 11777212;
		}

		$post = sprintf( '{"embeds": [{ "color" : "' . $colour . '" ,"description" : "%s"}], "username": "%s"',
			$message,
			$discordFromName
		);

		if ( $this->options->get( 'DiscordAvatarUrl' ) ) {
			$post .= ', "avatar_url": "' . $this->options->get( 'DiscordAvatarUrl' ) . '"';
		}

		$post .= '}';

		// Use file_get_contents to send the data. Note that you will need to have allow_url_fopen enabled in php.ini for this to work.
		if ( $this->options->get( 'DiscordSendMethod' ) == 'file_get_contents' ) {
			self::sendHttpRequest( $webhook ?? $this->options->get( 'DiscordIncomingWebhookUrl' ), $post );

			if ( !$webhook && $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) && is_array( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) ) ) {
				for ( $i = 0; $i < count( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) ); ++$i ) {
					self::sendHttpRequest( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' )[$i], $post );
				}
			}
		} else {
			// Call the Discord API through cURL (default way). Note that you will need to have cURL enabled for this to work.
			$this->sendCurlRequest( $webhook ?? $this->options->get( 'DiscordIncomingWebhookUrl' ), $post );

			if ( !$webhook && $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) && is_array( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) ) ) {
				for ( $i = 0; $i < count( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) ); ++$i ) {
					$this->sendCurlRequest( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' )[$i], $post );
				}
			}
		}
	}

	/**
	 * @param string $url
	 * @param string $postData
	 */
	private function sendCurlRequest( string $url, string $postData ) {
		$h = curl_init();
		curl_setopt( $h, CURLOPT_URL, $url );

		if ( $this->options->get( 'DiscordCurlProxy' ) ) {
			curl_setopt( $h, CURLOPT_PROXY, $this->options->get( 'DiscordCurlProxy' ) );
		}

		curl_setopt( $h, CURLOPT_POST, 1 );
		curl_setopt( $h, CURLOPT_POSTFIELDS, $postData );
		curl_setopt( $h, CURLOPT_RETURNTRANSFER, true );

		// Set 10 second timeout to connection
		curl_setopt( $h, CURLOPT_CONNECTTIMEOUT, 10 );

		// Set global 10 second timeout to handle all data
		curl_setopt( $h, CURLOPT_TIMEOUT, 10 );

		// Set Content-Type to application/json
		curl_setopt( $h, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen( $postData )
		] );

		// Execute the curl script
		$curl_output = curl_exec( $h );

		curl_close( $h );
	}

	/**
	 * @param string $url
	 * @param string $postData
	 */
	private static function sendHttpRequest( string $url, string $postData ) {
		$extraData = [
			'http' => [
				'header'  => 'Content-type: application/json',
				'method'  => 'POST',
				'content' => $postData,
			],
		];

		$context = stream_context_create( $extraData );
		$result = file_get_contents( $url, false, $context );
	}
}
