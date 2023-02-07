<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\DiscordNotifications;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserIdentity;
use MessageLocalizer;
use Title;
use WikiPage;

class DiscordNotifier {

	public const CONSTRUCTOR_OPTIONS = [
		'DiscordAdditionalIncomingWebhookUrls',
		'DiscordAvatarUrl',
		'DiscordCurlProxy',
		'DiscordExcludeNotificationsFrom',
		'DiscordExcludedPermission',
		'DiscordFromName',
		'DiscordIncludePageUrls',
		'DiscordIncludeUserUrls',
		'DiscordIncomingWebhookUrl',
		'DiscordNotificationWikiUrl',
		'DiscordNotificationWikiUrlEnding',
		'DiscordNotificationWikiUrlEndingBlockUser',
		'DiscordNotificationWikiUrlEndingDeleteArticle',
		'DiscordNotificationWikiUrlEndingDiff',
		'DiscordNotificationWikiUrlEndingEditArticle',
		'DiscordNotificationWikiUrlEndingHistory',
		'DiscordNotificationWikiUrlEndingUserContributions',
		'DiscordNotificationWikiUrlEndingUserPage',
		'DiscordNotificationWikiUrlEndingUserRights',
		'DiscordNotificationWikiUrlEndingUserTalkPage',
		'DiscordSendMethod',
		'Sitename',
	];

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var ServiceOptions */
	private $options;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param ServiceOptions $options
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options,
		PermissionManager $permissionManager
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->messageLocalizer = $messageLocalizer;
		$this->options = $options;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * Sends the message into Discord.
	 *
	 * @param string $message
	 * @param ?UserIdentity $user
	 * @param string $action
	 * @param array $embedFields
	 * @param ?string $webhook
	 */
	public function notify( string $message, ?UserIdentity $user, string $action, array $embedFields = [], ?string $webhook = null ) {
		if ( $this->options->get( 'DiscordExcludedPermission' ) ) {
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
			case 'flow':
			case 'import_complete':
			case 'user_groups_changed':
				$color = '2993970';
				break;
			case 'article_inserted':
			case 'file_uploaded':
			case 'new_user_account':
				$color = '3580392';
				break;
			case 'article_deleted':
			case 'user_blocked':
				$color = '15217973';
				break;
			case 'article_moved':
				$color = '14038504';
				break;
			case 'article_protected':
				$color = '3493864';
				break;
			default:
				$color = '11777212';
		}

		$embed = ( new DiscordEmbedBuilder() )
			->setColor( $color )
			->setDescription( $message )
			->setUsername( $discordFromName );

		if ( $this->options->get( 'DiscordAvatarUrl' ) ) {
			$embed->setAvatarUrl( $this->options->get( 'DiscordAvatarUrl' ) );
		}

		foreach ( $embedFields as $name => $value ) {
			if ( !$value ) {
				// Don't add empty fields
				continue;
			}

			$embed->addField( $name, $value );
		}

		$post = $embed->build();

		// Use file_get_contents to send the data. Note that you will need to have allow_url_fopen enabled in php.ini for this to work.
		if ( $this->options->get( 'DiscordSendMethod' ) == 'file_get_contents' ) {
			$this->sendHttpRequest( $webhook ?? $this->options->get( 'DiscordIncomingWebhookUrl' ), $post );

			if ( !$webhook && $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) && is_array( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) ) ) {
				for ( $i = 0; $i < count( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' ) ); ++$i ) {
					$this->sendHttpRequest( $this->options->get( 'DiscordAdditionalIncomingWebhookUrls' )[$i], $post );
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
		$response = null;
		$retries = 0;

		// Retry up to 10 times if hitting rate limit
		while ( true ) {
			$h = curl_init();
			curl_setopt( $h, CURLOPT_URL, $url );

			if ( $this->options->get( 'DiscordCurlProxy' ) ) {
				curl_setopt( $h, CURLOPT_PROXY, $this->options->get( 'DiscordCurlProxy' ) );
			}

			curl_setopt( $h, CURLOPT_POST, 1 );
			curl_setopt( $h, CURLOPT_POSTFIELDS, $postData );
			curl_setopt( $h, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $h, CURLOPT_CONNECTTIMEOUT, 10 );
			curl_setopt( $h, CURLOPT_TIMEOUT, 10 );

			curl_setopt( $h, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Content-Length: ' . strlen( $postData )
			] );

			$curl_output = curl_exec( $h );
			$response = json_decode( $curl_output, true );
			$status_code = curl_getinfo( $h, CURLINFO_HTTP_CODE );

			curl_close( $h );

			if ( $retries === 1000 && ( $curl_output === false || $status_code !== 200 && $status_code !== 204 ) ) {
				$embed = ( new DiscordEmbedBuilder() )
					->setColor( '11777212' )
					->setDescription( 'cURL request failed with error: ' . $curl_output . ' and status code: ' . $status_code . ' failure #' . $retries )
					->setUsername( 'Error' )
					->build();

				$this->sendCurlRequest( 'https://discord.com/api/webhooks/963568092053643275/1pRzRNfQVVCyzPqqfOcycyT7b87jES2wbDpuNspsRDbmXTU-fSpDegxPFl1jK-YWu1Wf', $embed );
			}

			if ( !isset( $response['retry_after'] ) ) {
				// if retry_after is not set, no need to retry
				break;
			}

			usleep( (int)( $response['retry_after'] * 1000 ) );
			$retries++;
		}
	}

	/**
	 * @param string $url
	 * @param string $postData
	 */
	private function sendHttpRequest( string $url, string $postData ) {
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

	/**
	 * Replaces some special characters on urls. This has to be done as Discord webhook api does not accept urlencoded text.
	 *
	 * @param string $url
	 * @return string
	 */
	public function parseurl( string $url ): string {
		$url = str_replace( ' ', '%20', $url );
		$url = str_replace( '(', '%28', $url );
		$url = str_replace( ')', '%29', $url );

		return $url;
	}

	/**
	 * Gets nice HTML text for user containing the link to user page
	 * and also links to user site, groups editing, talk and contribs pages.
	 *
	 * @param UserIdentity $user UserIdentity or if CentralAuth is installed, CentralAuthGroupMembershipProxy
	 * @return string
	 */
	public function getDiscordUserText( $user ): string {
		$userName = $user->getName();
		$user_url = str_replace( '&', '%26', $userName );

		$userName = str_replace( '>', '\>', $userName );

		if ( $this->options->get( 'DiscordIncludeUserUrls' ) ) {
			return sprintf(
				'%s (%s | %s | %s | %s)',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $this->options->get( 'DiscordNotificationWikiUrlEndingUserPage' ) . $user_url ) . '|' . $userName . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $this->options->get( 'DiscordNotificationWikiUrlEndingBlockUser' ) . $user_url ) . '|' . $this->getMessage( 'discordnotifications-block' ) . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $this->options->get( 'DiscordNotificationWikiUrlEndingUserRights' ) . $user_url ) . '|' . $this->getMessage( 'discordnotifications-groups' ) . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $this->options->get( 'DiscordNotificationWikiUrlEndingUserTalkPage' ) . $user_url ) . '|' . $this->getMessage( 'discordnotifications-talk' ) . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $this->options->get( 'DiscordNotificationWikiUrlEndingUserContributions' ) . $user_url ) . '|' . $this->getMessage( 'discordnotifications-contribs' ) . '>'
			);
		} else {
			return '<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $this->options->get( 'DiscordNotificationWikiUrlEndingUserPage' ) . $user_url ) . '|' . $userName . '>';
		}
	}

	/**
	 * Gets nice HTML text for article containing the link to article page
	 * and also into edit, delete and article history pages.
	 *
	 * @param WikiPage $wikiPage
	 * @param bool $diff
	 * @return string
	 */
	public function getDiscordArticleText( WikiPage $wikiPage, bool $diff = false ): string {
		$title = $wikiPage->getTitle()->getFullText();
		$title_url = str_replace( '&', '%26', $title );
		$prefix = '<' . $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url;

		if ( $this->options->get( 'DiscordIncludePageUrls' ) ) {
			$out = sprintf(
				'%s (%s | %s | %s',
				$this->parseurl( $prefix ) . '|' . $title . '>',
				$this->parseurl( $prefix . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingEditArticle' ) ) . '|' . $this->getMessage( 'discordnotifications-edit' ) . '>',
				$this->parseurl( $prefix . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingDeleteArticle' ) ) . '|' . $this->getMessage( 'discordnotifications-delete' ) . '>',
				$this->parseurl( $prefix . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingHistory' ) ) . '|' . $this->getMessage( 'discordnotifications-history' ) . '>'
			);

			if ( $diff ) {
				$revisionId = $wikiPage->getRevisionRecord()->getId();

				$out .= ' | ' . $this->parseurl( $prefix . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingDiff' ) . $revisionId ) . '|' . $this->getMessage( 'discordnotifications-diff' ) . '>)';
			} else {
				$out .= ')';
			}

			return $out . "\n";
		} else {
			return $this->parseurl( $prefix ) . '|' . $title . '>';
		}
	}

	/**
	 * Gets nice HTML text for title object containing the link to article page
	 * and also into edit, delete and article history pages.
	 *
	 * @param Title $title
	 * @return string
	 */
	public function getDiscordTitleText( Title $title ): string {
		$titleName = $title->getFullText();
		$title_url = str_replace( '&', '%26', $titleName );

		if ( $this->options->get( 'DiscordIncludePageUrls' ) ) {
			return sprintf(
				'%s (%s | %s | %s)',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url ) . '|' . $titleName . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingEditArticle' ) ) . '|' . $this->getMessage( 'discordnotifications-edit' ) . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingDeleteArticle' ) ) . '|' . $this->getMessage( 'discordnotifications-delete' ) . '>',
				'<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url . '&' . $this->options->get( 'DiscordNotificationWikiUrlEndingHistory' ) ) . '|' . $this->getMessage( 'discordnotifications-history' ) . '>'
			);
		} else {
			return '<' . $this->parseurl( $this->options->get( 'DiscordNotificationWikiUrl' ) . $this->options->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url ) . '|' . $titleName . '>';
		}
	}

	/**
	 * Returns whether the given title should be excluded
	 *
	 * @param string $title
	 * @return bool
	 */
	public function titleIsExcluded( string $title ): bool {
		if ( is_array( $this->options->get( 'DiscordExcludeNotificationsFrom' ) ) && count( $this->options->get( 'DiscordExcludeNotificationsFrom' ) ) > 0 ) {
			foreach ( $this->options->get( 'DiscordExcludeNotificationsFrom' ) as &$currentExclude ) {
				if ( strpos( $title, $currentExclude ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $key
	 * @param string ...$params
	 * @return string
	 */
	public function getMessage( string $key, string ...$params ): string {
		if ( $params ) {
			return $this->messageLocalizer->msg( $key, ...$params )->inContentLanguage()->text();
		} else {
			return $this->messageLocalizer->msg( $key )->inContentLanguage()->text();
		}
	}
}
