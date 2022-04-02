<?php

namespace MediaWiki\Extension\DiscordNotifications;

use APIBase;
use Config;
use ConfigFactory;
use Exception;
use ExtensionRegistry;
use MediaWiki\Hook\AddNewAccountHook;
use MediaWiki\Hook\AfterImportPageHook;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use RequestContext;
use Title;
use WikiPage;

class Hooks implements
	AddNewAccountHook,
	AfterImportPageHook,
	ArticleDeleteCompleteHook,
	ArticleProtectCompleteHook,
	BlockIpCompleteHook,
	PageMoveCompleteHook,
	PageSaveCompleteHook,
	UploadCompleteHook,
	UserGroupsChangedHook
{
	/** @var Config */
	private $config;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var RevisionLookup */
	private $revisionLookup;

	/**
	 * @param ConfigFactory $configFactory
	 * @param PermissionManager $permissionManager
	 * @param RevisionLookup $revisionLookup
	 */
	public function __construct(
		ConfigFactory $configFactory,
		PermissionManager $permissionManager,
		RevisionLookup $revisionLookup
	) {
		$this->config = $configFactory->makeConfig( 'DiscordNotifications' );
		$this->permissionManager = $permissionManager;
		$this->revisionLookup = $revisionLookup;
	}

	/**
	 * Replaces some special characters on urls. This has to be done as Discord webhook api does not accept urlencoded text.
	 */
	private static function parseurl( $url ) {
		$url = str_replace( " ", "%20", $url );
		$url = str_replace( "(", "%28", $url );
		$url = str_replace( ")", "%29", $url );

		return $url;
	}

	/**
	 * Gets nice HTML text for user containing the link to user page
	 * and also links to user site, groups editing, talk and contribs pages.
	 */
	private static function getDiscordUserText( $user ) {
		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding, $wgDiscordNotificationWikiUrlEndingUserPage,
			$wgDiscordNotificationWikiUrlEndingBlockUser, $wgDiscordNotificationWikiUrlEndingUserRights,
			$wgDiscordNotificationWikiUrlEndingUserTalkPage, $wgDiscordNotificationWikiUrlEndingUserContributions,
			$wgDiscordIncludeUserUrls;

		$userName = is_object( $user ) ? $user->getName() : $user;
		$user_url = str_replace( "&", "%26", $userName );
		if ( $wgDiscordIncludeUserUrls ) {
			return sprintf(
				"%s (%s | %s | %s | %s)",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingUserPage . $user_url ) . "|$userName>",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingBlockUser . $user_url ) . "|" . self::msg( 'discordnotifications-block' ) . ">",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingUserRights . $user_url ) . "|" . self::msg( 'discordnotifications-groups' ) . ">",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingUserTalkPage . $user_url ) . "|" . self::msg( 'discordnotifications-talk' ) . ">",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingUserContributions . $user_url ) . "|" . self::msg( 'discordnotifications-contribs' ) . ">" );
		} else {
			return "<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingUserPage . $user_url ) . "|$userName>";
		}
	}

	/**
	 * Gets nice HTML text for article containing the link to article page
	 * and also into edit, delete and article history pages.
	 */
	private static function getDiscordArticleText( WikiPage $article, $diff = false ) {
		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding, $wgDiscordNotificationWikiUrlEndingEditArticle,
			$wgDiscordNotificationWikiUrlEndingDeleteArticle, $wgDiscordNotificationWikiUrlEndingHistory,
			$wgDiscordNotificationWikiUrlEndingDiff, $wgDiscordIncludePageUrls;

		$title = $article->getTitle()->getFullText();
		$title_url = str_replace( "&", "%26", $title );
		$prefix = "<" . $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $title_url;
		if ( $wgDiscordIncludePageUrls ) {
			$out = sprintf(
				"%s (%s | %s | %s",
				self::parseurl( $prefix ) . "|" . $title . ">",
				self::parseurl( $prefix . "&" . $wgDiscordNotificationWikiUrlEndingEditArticle ) . "|" . self::msg( 'discordnotifications-edit' ) . ">",
				self::parseurl( $prefix . "&" . $wgDiscordNotificationWikiUrlEndingDeleteArticle ) . "|" . self::msg( 'discordnotifications-delete' ) . ">",
				self::parseurl( $prefix . "&" . $wgDiscordNotificationWikiUrlEndingHistory ) . "|" . self::msg( 'discordnotifications-history' ) . ">"/*,
					"move",
					"protect",
					"watch"*/ );
			if ( $diff ) {
				$revisionId = $article->getRevisionRecord()->getId();

				$out .= " | " . self::parseurl( $prefix . "&" . $wgDiscordNotificationWikiUrlEndingDiff . $revisionId ) . "|" . self::msg( 'discordnotifications-diff' ) . ">)";
			} else {
				$out .= ")";
			}
			return $out . "\n";
		} else {
			return self::parseurl( $prefix ) . "|" . $title . ">";
		}
	}

	/**
	 * Gets nice HTML text for title object containing the link to article page
	 * and also into edit, delete and article history pages.
	 */
	private static function getDiscordTitleText( Title $title ) {
		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding, $wgDiscordNotificationWikiUrlEndingEditArticle,
			$wgDiscordNotificationWikiUrlEndingDeleteArticle, $wgDiscordNotificationWikiUrlEndingHistory,
			$wgDiscordIncludePageUrls;

		$titleName = $title->getFullText();
		$title_url = str_replace( "&", "%26", $titleName );
		if ( $wgDiscordIncludePageUrls ) {
			return sprintf(
				"%s (%s | %s | %s)",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $title_url ) . "|" . $titleName . ">",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $title_url . "&" . $wgDiscordNotificationWikiUrlEndingEditArticle ) . "|" . self::msg( 'discordnotifications-edit' ) . ">",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $title_url . "&" . $wgDiscordNotificationWikiUrlEndingDeleteArticle ) . "|" . self::msg( 'discordnotifications-delete' ) . ">",
				"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $title_url . "&" . $wgDiscordNotificationWikiUrlEndingHistory ) . "|" . self::msg( 'discordnotifications-history' ) . ">"/*,
						"move",
						"protect",
						"watch"*/ );
		} else {
			return "<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $title_url ) . "|" . $titleName . ">";
		}
	}

	/**
	 * Returns whether the given title should be excluded
	 */
	private static function titleIsExcluded( $title ) {
		global $wgDiscordExcludeNotificationsFrom;

		if ( is_array( $wgDiscordExcludeNotificationsFrom ) && count( $wgDiscordExcludeNotificationsFrom ) > 0 ) {
			foreach ( $wgDiscordExcludeNotificationsFrom as &$currentExclude ) {
				if ( strpos( $title, $currentExclude ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Occurs after an article has been updated.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		global $wgDiscordNotificationEditedArticle, $wgDiscordIgnoreMinorEdits,
			$wgDiscordNotificationAddedArticle, $wgDiscordIncludeDiffSize;
		$isNew = (bool)( $flags & EDIT_NEW );

		if ( !$wgDiscordNotificationEditedArticle && !$isNew ) return;
		if ( !$wgDiscordNotificationAddedArticle && $isNew ) return;
		if ( self::titleIsExcluded( $wikiPage->getTitle() ) ) return;

		// Do not announce newly added file uploads as articles...
		if ( $wikiPage->getTitle()->getNsText() && $wikiPage->getTitle()->getNsText() == self::msg( 'discordnotifications-file-namespace' ) ) return;

		if ( $isNew ) {
			$message = self::msg( 'discordnotifications-article-created',
			self::getDiscordUserText( $user ),
			self::getDiscordArticleText( $wikiPage ),
			$summary == "" ? "" : wfMessage( 'discordnotifications-summary' )->plaintextParams( $summary ) );
			if ( $wgDiscordIncludeDiffSize ) {
				$message .= " (" . self::msg( 'discordnotifications-bytes', $revisionRecord->getSize() ) . ")";
			}

			$this->pushDiscordNotify( $message, $user, 'article_inserted' );
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );
			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $wgDiscordIgnoreMinorEdits ) return;

			$message = self::msg(
				'discordnotifications-article-saved',
				self::getDiscordUserText( $user ),
				$isMinor ? self::msg( 'discordnotifications-article-saved-minor-edits' ) : self::msg( 'discordnotifications-article-saved-edit' ),
				self::getDiscordArticleText( $wikiPage, true ),
				$summary == "" ? "" : wfMessage( 'discordnotifications-summary' )->plaintextParams( $summary ) );
			if (
				$wgDiscordIncludeDiffSize &&
				$this->revisionLookup->getPreviousRevision( $revisionRecord )
			) {
				$message .= ' (' . self::msg( 'discordnotifications-bytes',
					$revisionRecord->getSize() - $this->revisionLookup->getPreviousRevision( $revisionRecord )->getSize() ) . ')';
			}

			$this->pushDiscordNotify( $message, $user, 'article_saved' );
		}
	}

	/**
	 * Occurs after the delete article request has been processed.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 */
	public function onArticleDeleteComplete( $wikiPage, $user, $reason, $id,
		$content, $logEntry, $archivedRevisionCount ) {
		global $wgDiscordNotificationRemovedArticle;
		if ( !$wgDiscordNotificationRemovedArticle ) return;

		global $wgDiscordNotificationShowSuppressed;
		if ( !$wgDiscordNotificationShowSuppressed && $logEntry->getType() != 'delete' ) return;

		if ( self::titleIsExcluded( $wikiPage->getTitle() ) ) return;

		$message = wfMessage( 'discordnotifications-article-deleted' )->plaintextParams(
			self::getDiscordUserText( $user ),
			self::getDiscordArticleText( $wikiPage ),
			$reason
		)->inContentLanguage()->text();

		$this->pushDiscordNotify( $message, $user, 'article_deleted' );
	}

	/**
	 * Occurs after a page has been moved.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageMoveComplete
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		global $wgDiscordNotificationMovedArticle;
		if ( !$wgDiscordNotificationMovedArticle ) return;

		$message = self::msg( 'discordnotifications-article-moved',
			self::getDiscordUserText( $user ),
			self::getDiscordTitleText( $old ),
			self::getDiscordTitleText( $new ),
			$reason );

		$this->pushDiscordNotify( $message, $user, 'article_moved' );
	}

	/**
	 * Occurs after the protect article request has been processed.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleProtectComplete
	 */
	public function onArticleProtectComplete( $wikiPage, $user, $protect, $reason ) {
		global $wgDiscordNotificationProtectedArticle;
		if ( !$wgDiscordNotificationProtectedArticle ) return;

		$message = self::msg( 'discordnotifications-article-protected',
			self::getDiscordUserText( $user ),
			$protect ? self::msg( 'discordnotifications-article-protected-change' ) : self::msg( 'discordnotifications-article-protected-remove' ),
			self::getDiscordArticleText( $wikiPage ),
			$reason );

		$this->pushDiscordNotify( $message, $user, 'article_protected' );
	}

	/**
	 * Occurs after page has been imported into wiki.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/AfterImportPage
	 */
	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ) {
		global $wgDiscordNotificationAfterImportPage;
		if ( !$wgDiscordNotificationAfterImportPage ) return;

		$message = self::msg( 'discordnotifications-import-complete',
			self::getDiscordTitleText( $title ) );
		$this->pushDiscordNotify( $message, null, 'import_complete' );
	}

	/**
	 * Called after a user account is created.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
	 */
	public function onAddNewAccount( $user, $byEmail ) {
		global $wgDiscordNotificationNewUser, $wgDiscordShowNewUserFullName,
			$wgDiscordShowNewUserEmail, $wgDiscordShowNewUserIP;

		if ( !$wgDiscordNotificationNewUser ) {
			return;
		}

		$email = '';
		$realname = '';
		$ipaddress = '';

		try {
			$email = $user->getEmail();
		} catch ( Exception $e ) {
		}

		try {
			$realname = $user->getRealName();
		} catch ( Exception $e ) {
		}

		try {
			$ipaddress = $user->getRequest()->getIP();
		} catch ( Exception $e ) {
		}

		$messageExtra = '';
		if ( $wgDiscordShowNewUserEmail || $wgDiscordShowNewUserFullName || $wgDiscordShowNewUserIP ) {
			$messageExtra = '(';

			if ( $wgDiscordShowNewUserEmail ) {
				$messageExtra .= $email . ', ';
			}

			if ( $wgDiscordShowNewUserFullName ) {
				$messageExtra .= $realname . ', ';
			}

			if ( $wgDiscordShowNewUserIP ) {
				$messageExtra .= $ipaddress . ', ';
			}

			// Remove trailing comma
			$messageExtra = substr( $messageExtra, 0, -2 );
			$messageExtra .= ')';
		}

		$message = self::msg( 'discordnotifications-new-user',
			self::getDiscordUserText( $user ),
			$messageExtra );

		$this->pushDiscordNotify( $message, $user, 'new_user_account' );
	}

	/**
	 * Called when a file upload has completed.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete
	 */
	public function onUploadComplete( $uploadBase ) {
		global $wgDiscordNotificationFileUpload, $wgDiscordNotificationWikiUrl,
			$wgDiscordNotificationWikiUrlEnding;

		if ( !$wgDiscordNotificationFileUpload ) {
			return;
		}

		$localFile = $uploadBase->getLocalFile();

		# Use bytes, KiB, and MiB, rounded to two decimal places.
		$fsize = $localFile->size;
		$funits = '';
		if ( $localFile->size < 2048 ) {
			$funits = 'bytes';
		} elseif ( $localFile->size < 2048 * 1024 ) {
			$fsize /= 1024;
			$fsize = round( $fsize, 2 );
			$funits = 'KiB';
		} else {
			$fsize /= 1024 * 1024;
			$fsize = round( $fsize, 2 );
			$funits = 'MiB';
		}

		$user = RequestContext::getMain()->getUser();

		$message = self::msg( 'discordnotifications-file-uploaded',
			self::getDiscordUserText( $user ),
			self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $image->getLocalFile()->getTitle() ),
			$localFile->getTitle(),
			$localFile->getMimeType(),
			$fsize, $funits,
			$localFile->getDescription() );

		$this->pushDiscordNotify( $message, $user, 'file_uploaded' );
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 * @see http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/BlockIpComplete
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		global $wgDiscordNotificationBlockedUser, $wgDiscordNotificationWikiUrl,
			$wgDiscordNotificationWikiUrlEnding, $wgDiscordNotificationWikiUrlEndingBlockList;

		if ( !$wgDiscordNotificationBlockedUser ) {
			return;
		}

		$reason = $block->getReasonComment()->text;

		$message = self::msg( 'discordnotifications-block-user',
			self::getDiscordUserText( $user ),
			self::getDiscordUserText( $block->getTarget() ),
			$reason == '' ? '' : self::msg( 'discordnotifications-block-user-reason' ) . " '" . $reason . "'.",
			$block->getExpiry(),
			'<' . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingBlockList ) . '|' . self::msg( 'discordnotifications-block-user-list' ) . '>.' );

		$this->pushDiscordNotify( $message, $user, 'user_blocked' );
	}

	/**
	 * Occurs after the user groups (rights) have been changed
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserGroupsChanged
	 */
	public static function onUserGroupsChanged( $user, $added, $removed, $performer, $reason, $oldUGMs, $newUGMs ) {
		global $wgDiscordNotificationUserGroupsChanged;
		if ( !$wgDiscordNotificationUserGroupsChanged ) return;

		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding, $wgDiscordNotificationWikiUrlEndingUserRights;
		$message = self::msg( 'discordnotifications-change-user-groups-with-old',
			self::getDiscordUserText( $performer ),
			self::getDiscordUserText( $user ),
			implode( ", ", array_keys( $oldUGMs ) ),
			implode( ", ", $user->getGroups() ),
			"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $wgDiscordNotificationWikiUrlEndingUserRights . self::getDiscordUserText( $performer ) ) . "|" . self::msg( 'discordnotifications-view-user-rights' ) . ">." );

		$this->pushDiscordNotify( $message, $user, 'user_groups_changed' );
	}

	/**
	 * Occurs after the execute() method of an Flow API module
	 */
	public function onAPIFlowAfterExecute( APIBase $module ) {
		global $wgDiscordNotificationFlow;
		if ( !$wgDiscordNotificationFlow || !ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) return;

		$request = RequestContext::getMain()->getRequest();

		$action = $module->getModuleName();
		$request = $request->getValues();
		$result = $module->getResult()->getResultData()['flow'][$action];
		if ( $result['status'] != 'ok' ) return;

		if ( self::titleIsExcluded( $request['page'] ) ) return;

		global $wgDiscordNotificationWikiUrl, $wgDiscordNotificationWikiUrlEnding;

		$user = RequestContext::getMain()->getUser();

		switch ( $action ) {
			case 'edit-header':
				$message = self::msg( "discordnotifications-flow-edit-header",
					self::getDiscordUserText( $user ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $request['page'] ) . "|" . $request['page'] . ">" );
				break;
			case 'edit-post':
				$message = self::msg( "discordnotifications-flow-edit-post",
					self::getDiscordUserText( $user ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . "Topic:" . $result['workflow'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			case 'edit-title':
				$message = self::msg( "discordnotifications-flow-edit-title",
					self::getDiscordUserText( $user ),
					$request['etcontent'],
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . 'Topic:' . $result['workflow'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			case 'edit-topic-summary':
				$message = self::msg( "discordnotifications-flow-edit-topic-summary",
					self::getDiscordUserText( $user ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . 'Topic:' . $result['workflow'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			case 'lock-topic':
				$message = self::msg( "discordnotifications-flow-lock-topic",
					self::getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-lock-topic-lock
					// * discordnotifications-flow-lock-topic-unlock
					self::msg( "discordnotifications-flow-lock-topic-" . $request['cotmoderationState'] ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $request['page'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			case 'moderate-post':
				$message = self::msg( "discordnotifications-flow-moderate-post",
					self::getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					self::msg( "discordnotifications-flow-moderate-" . $request['mpmoderationState'] ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $request['page'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			case 'moderate-topic':
				$message = self::msg( "discordnotifications-flow-moderate-topic",
					self::getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					self::msg( "discordnotifications-flow-moderate-" . $request['mtmoderationState'] ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $request['page'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			case 'new-topic':
				$message = self::msg( "discordnotifications-flow-new-topic",
					self::getDiscordUserText( $user ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . "Topic:" . $result['committed']['topiclist']['topic-id'] ) . "|" . $request['nttopic'] . ">",
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . $request['page'] ) . "|" . $request['page'] . ">" );
				break;
			case 'reply':
				$message = self::msg( "discordnotifications-flow-reply",
					self::getDiscordUserText( $user ),
					"<" . self::parseurl( $wgDiscordNotificationWikiUrl . $wgDiscordNotificationWikiUrlEnding . 'Topic:' . $result['workflow'] ) . "|" . self::flowUUIDToTitleText( $result['workflow'] ) . ">" );
				break;
			default:
				return;
		}

		$this->pushDiscordNotify( $message, $user, 'flow' );
	}

	/**
	 * Sends the message into Discord room.
	 * @param string $message Message to be sent.
	 * @see https://discordapp.com/developers/docs/resources/webhook#execute-webhook
	 */
	private function pushDiscordNotify( $message, $user, $action ) {
		global $wgDiscordIncomingWebhookUrl, $wgDiscordFromName, $wgDiscordAvatarUrl, $wgDiscordSendMethod, $wgDiscordExcludedPermission, $wgSitename, $wgDiscordAdditionalIncomingWebhookUrls;

		if ( isset( $wgDiscordExcludedPermission ) && $wgDiscordExcludedPermission != "" ) {
			if ( $user && $this->permissionManager->userHasRight( $user, $wgDiscordExcludedPermission ) ) {
				return; // Users with the permission suppress notifications
			}
		}

		// Convert " to ' in the message to be sent as otherwise JSON formatting would break.
		$message = str_replace( '"', "'", $message );

		$discordFromName = $wgDiscordFromName;
		if ( $discordFromName == "" ) {
			$discordFromName = $wgSitename;
		}

		$message = preg_replace( "~(<)(http)([^|]*)(\|)([^\>]*)(>)~", "[$5]($2$3)", $message );
		$message = str_replace( [ "\r", "\n" ], '', $message );

		$colour = 11777212;
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
				break;
		}

		$post = sprintf( '{"embeds": [{ "color" : "' . $colour . '" ,"description" : "%s"}], "username": "%s"',
		$message,
		$discordFromName );
		if ( isset( $wgDiscordAvatarUrl ) && !empty( $wgDiscordAvatarUrl ) ) {
			$post .= ', "avatar_url": "' . $wgDiscordAvatarUrl . '"';
		}
		$post .= '}';

		// Use file_get_contents to send the data. Note that you will need to have allow_url_fopen enabled in php.ini for this to work.
		if ( $wgDiscordSendMethod == "file_get_contents" ) {
			self::sendHttpRequest( $wgDiscordIncomingWebhookUrl, $post );
			if ( $wgDiscordAdditionalIncomingWebhookUrls && is_array( $wgDiscordAdditionalIncomingWebhookUrls ) ) {
				for ( $i = 0; $i < count( $wgDiscordAdditionalIncomingWebhookUrls ); ++$i ) {
					self::sendHttpRequest( $wgDiscordAdditionalIncomingWebhookUrls[$i], $post );
				}
			}
		} else {
			// Call the Discord API through cURL (default way). Note that you will need to have cURL enabled for this to work.
			self::sendCurlRequest( $wgDiscordIncomingWebhookUrl, $post );
			if ( $wgDiscordAdditionalIncomingWebhookUrls && is_array( $wgDiscordAdditionalIncomingWebhookUrls ) ) {
				for ( $i = 0; $i < count( $wgDiscordAdditionalIncomingWebhookUrls ); ++$i ) {
					self::sendCurlRequest( $wgDiscordAdditionalIncomingWebhookUrls[$i], $post );
				}
			}
		}
	}

	private static function sendCurlRequest( $url, $postData ) {
		global $wgDiscordCurlProxy;

		$h = curl_init();
		curl_setopt( $h, CURLOPT_URL, $url );

		if ( $wgDiscordCurlProxy ) {
			curl_setopt( $h, CURLOPT_PROXY, $wgDiscordCurlProxy );
		}

		curl_setopt( $h, CURLOPT_POST, 1 );
		curl_setopt( $h, CURLOPT_POSTFIELDS, $postData );
		curl_setopt( $h, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $h, CURLOPT_CONNECTTIMEOUT, 10 ); // Set 10 second timeout to connection
		curl_setopt( $h, CURLOPT_TIMEOUT, 10 ); // Set global 10 second timeout to handle all data
		curl_setopt( $h, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen( $postData )
		] ); // Set Content-Type to application/json
		// Commented out lines below. Using default curl settings for host and peer verification.
		//curl_setopt ($h, CURLOPT_SSL_VERIFYHOST, 0);
		//curl_setopt ($h, CURLOPT_SSL_VERIFYPEER, 0);
		// ... Aaand execute the curl script!
		$curl_output = curl_exec( $h );
		curl_close( $h );
	}

	private static function sendHttpRequest( $url, $postData ) {
		$extradata = [
			'http' => [
				'header'  => "Content-type: application/json",
				'method'  => 'POST',
				'content' => $postData,
			],
		];

		$context = stream_context_create( $extradata );
		$result = file_get_contents( $url, false, $context );
	}

	private static function msg( $key, ...$params ) {
		if ( $params ) {
			return wfMessage( $key, ...$params )->inContentLanguage()->text();
		} else {
			return wfMessage( $key )->inContentLanguage()->text();
		}
	}

	private static function flowUUIDToTitleText( $UUID ) {
		$UUID = \Flow\Model\UUID::create( $UUID );
		$collection = \Flow\Collection\PostCollection::newFromId( $UUID );
		$revision = $collection->getLastRevision();

		return $revision->getContent( 'topic-title-plaintext' );
	}
}
