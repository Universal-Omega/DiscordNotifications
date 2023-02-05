<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\DiscordNotifications;

use APIBase;
use Config;
use ConfigFactory;
use Exception;
use ExtensionRegistry;
use Flow\Collection\PostCollection;
use Flow\Model\UUID;
use ManualLogEntry;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Hook\AfterImportPageHook;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\ActorStore;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use RequestContext;
use Title;
use TitleFactory;
use WikiPage;

class Hooks implements
	AfterImportPageHook,
	ArticleProtectCompleteHook,
	BlockIpCompleteHook,
	LocalUserCreatedHook,
	PageDeleteCompleteHook,
	PageMoveCompleteHook,
	PageSaveCompleteHook,
	UploadCompleteHook,
	UserGroupsChangedHook
{
	/** @var ActorStore */
	private $actorStore;

	/** @var Config */
	private $config;

	/** @var DiscordNotifier */
	private $discordNotifier;

	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/**
	 * @param ActorStore $actorStore
	 * @param ConfigFactory $configFactory
	 * @param DiscordNotifier $discordNotifier
	 * @param RevisionLookup $revisionLookup
	 * @param TitleFactory $titleFactory
	 * @param UserGroupManager $userGroupManager
	 * @param WikiPageFactory $wikiPageFactory
	 */
	public function __construct(
		ActorStore $actorStore,
		ConfigFactory $configFactory,
		DiscordNotifier $discordNotifier,
		RevisionLookup $revisionLookup,
		TitleFactory $titleFactory,
		UserGroupManager $userGroupManager,
		WikiPageFactory $wikiPageFactory
	) {
		$this->config = $configFactory->makeConfig( 'DiscordNotifications' );

		$this->actorStore = $actorStore;
		$this->discordNotifier = $discordNotifier;
		$this->revisionLookup = $revisionLookup;
		$this->titleFactory = $titleFactory;
		$this->userGroupManager = $userGroupManager;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * Replaces some special characters on urls. This has to be done as Discord webhook api does not accept urlencoded text.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function parseurl( string $url ): string {
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
	private function getDiscordUserText( $user ): string {
		$userName = $user->getName();
		$user_url = str_replace( '&', '%26', $userName );

		$userName = str_replace( '>', '\>', $userName );

		if ( $this->config->get( 'DiscordIncludeUserUrls' ) ) {
			return sprintf(
				'%s (%s | %s | %s | %s)',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserPage' ) . $user_url ) . '|' . $userName . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockUser' ) . $user_url ) . '|' . self::msg( 'discordnotifications-block' ) . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserRights' ) . $user_url ) . '|' . self::msg( 'discordnotifications-groups' ) . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserTalkPage' ) . $user_url ) . '|' . self::msg( 'discordnotifications-talk' ) . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserContributions' ) . $user_url ) . '|' . self::msg( 'discordnotifications-contribs' ) . '>'
			);
		} else {
			return '<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserPage' ) . $user_url ) . '|' . $userName . '>';
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
	private function getDiscordArticleText( WikiPage $wikiPage, bool $diff = false ): string {
		$title = $wikiPage->getTitle()->getFullText();
		$title_url = str_replace( '&', '%26', $title );
		$prefix = '<' . $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url;

		if ( $this->config->get( 'DiscordIncludePageUrls' ) ) {
			$out = sprintf(
				'%s (%s | %s | %s',
				self::parseurl( $prefix ) . '|' . $title . '>',
				self::parseurl( $prefix . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingEditArticle' ) ) . '|' . self::msg( 'discordnotifications-edit' ) . '>',
				self::parseurl( $prefix . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingDeleteArticle' ) ) . '|' . self::msg( 'discordnotifications-delete' ) . '>',
				self::parseurl( $prefix . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingHistory' ) ) . '|' . self::msg( 'discordnotifications-history' ) . '>'
			);

			if ( $diff ) {
				$revisionId = $wikiPage->getRevisionRecord()->getId();

				$out .= ' | ' . self::parseurl( $prefix . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingDiff' ) . $revisionId ) . '|' . self::msg( 'discordnotifications-diff' ) . '>)';
			} else {
				$out .= ')';
			}

			return $out . "\n";
		} else {
			return self::parseurl( $prefix ) . '|' . $title . '>';
		}
	}

	/**
	 * Gets nice HTML text for title object containing the link to article page
	 * and also into edit, delete and article history pages.
	 *
	 * @param Title $title
	 * @return string
	 */
	private function getDiscordTitleText( Title $title ): string {
		$titleName = $title->getFullText();
		$title_url = str_replace( '&', '%26', $titleName );

		if ( $this->config->get( 'DiscordIncludePageUrls' ) ) {
			return sprintf(
				'%s (%s | %s | %s)',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url ) . '|' . $titleName . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingEditArticle' ) ) . '|' . self::msg( 'discordnotifications-edit' ) . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingDeleteArticle' ) ) . '|' . self::msg( 'discordnotifications-delete' ) . '>',
				'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url . '&' . $this->config->get( 'DiscordNotificationWikiUrlEndingHistory' ) ) . '|' . self::msg( 'discordnotifications-history' ) . '>'
			);
		} else {
			return '<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $title_url ) . '|' . $titleName . '>';
		}
	}

	/**
	 * Returns whether the given title should be excluded
	 *
	 * @param string $title
	 * @return bool
	 */
	private function titleIsExcluded( string $title ): bool {
		if ( is_array( $this->config->get( 'DiscordExcludeNotificationsFrom' ) ) && count( $this->config->get( 'DiscordExcludeNotificationsFrom' ) ) > 0 ) {
			foreach ( $this->config->get( 'DiscordExcludeNotificationsFrom' ) as &$currentExclude ) {
				if ( strpos( $title, $currentExclude ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$isNew = (bool)( $flags & EDIT_NEW );

		if ( !$this->config->get( 'DiscordNotificationEditedArticle' ) && !$isNew ) {
			return;
		}

		if ( !$this->config->get( 'DiscordNotificationAddedArticle' ) && $isNew ) {
			return;
		}

		if ( $this->titleIsExcluded( $wikiPage->getTitle()->getText() ) ) {
			return;
		}

		// Do not announce newly added file uploads as articles...
		if ( $wikiPage->getTitle()->getNsText() && $wikiPage->getTitle()->getNsText() == self::msg( 'discordnotifications-file-namespace' ) ) {
			return;
		}

		if ( $isNew ) {
			$message = self::msg( 'discordnotifications-article-created',
				$this->getDiscordUserText( $user ),
				$this->getDiscordArticleText( $wikiPage ),
				$summary == '' ? '' : wfMessage( 'discordnotifications-summary' )->plaintextParams( $summary )->inContentLanguage()->text()
			);

			if ( $this->config->get( 'DiscordIncludeDiffSize' ) ) {
				$message .= ' (' . self::msg( 'discordnotifications-bytes', sprintf( '%d', $revisionRecord->getSize() ) ) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_inserted' );
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );

			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $this->config->get( 'DiscordIgnoreMinorEdits' ) ) {
				return;
			}

			$message = self::msg(
				'discordnotifications-article-saved',
				$this->getDiscordUserText( $user ),
				$isMinor ? self::msg( 'discordnotifications-article-saved-minor-edits' ) : self::msg( 'discordnotifications-article-saved-edit' ),
				$this->getDiscordArticleText( $wikiPage, true ),
				$summary == '' ? '' : wfMessage( 'discordnotifications-summary' )->plaintextParams( $summary )->inContentLanguage()->text()
			);

			if (
				$this->config->get( 'DiscordIncludeDiffSize' ) &&
				$this->revisionLookup->getPreviousRevision( $revisionRecord )
			) {
				$message .= ' (' . self::msg( 'discordnotifications-bytes',
					sprintf( '%+d', $revisionRecord->getSize() - $this->revisionLookup->getPreviousRevision( $revisionRecord )->getSize() )
				) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_saved' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount ) {
		if ( !$this->config->get( 'DiscordNotificationRemovedArticle' ) ) {
			return;
		}

		if ( !$this->config->get( 'DiscordNotificationShowSuppressed' ) && $logEntry->getType() != 'delete' ) {
			return;
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $page );

		if ( $this->titleIsExcluded( $wikiPage->getTitle()->getText() ) ) {
			return;
		}

		$message = wfMessage( 'discordnotifications-article-deleted' )->plaintextParams(
			$this->getDiscordUserText( $deleter->getUser() ),
			$this->getDiscordArticleText( $wikiPage ),
			$reason
		)->inContentLanguage()->text();

		$this->discordNotifier->notify( $message, $deleter->getUser(), 'article_deleted' );
	}

	/**
	 * @inheritDoc
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		if ( !$this->config->get( 'DiscordNotificationMovedArticle' ) ) {
			return;
		}

		$message = self::msg( 'discordnotifications-article-moved',
			$this->getDiscordUserText( $user ),
			$this->getDiscordTitleText( $this->titleFactory->newFromLinkTarget( $old ) ),
			$this->getDiscordTitleText( $this->titleFactory->newFromLinkTarget( $new ) ),
			$reason
		);

		$this->discordNotifier->notify( $message, $user, 'article_moved' );
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleProtectComplete( $wikiPage, $user, $protect, $reason ) {
		if ( !$this->config->get( 'DiscordNotificationProtectedArticle' ) ) {
			return;
		}

		$message = self::msg( 'discordnotifications-article-protected',
			$this->getDiscordUserText( $user ),
			$protect ? self::msg( 'discordnotifications-article-protected-change' ) : self::msg( 'discordnotifications-article-protected-remove' ),
			$this->getDiscordArticleText( $wikiPage ),
			$reason
		);

		$this->discordNotifier->notify( $message, $user, 'article_protected' );
	}

	/**
	 * @inheritDoc
	 */
	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ) {
		if ( !$this->config->get( 'DiscordNotificationAfterImportPage' ) ) {
			return;
		}

		$message = self::msg( 'discordnotifications-import-complete',
			$this->getDiscordTitleText( $title )
		);

		$this->discordNotifier->notify( $message, null, 'import_complete' );
	}

	/**
	 * @inheritDoc
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		if ( !$this->config->get( 'DiscordNotificationNewUser' ) ) {
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
		if ( $this->config->get( 'DiscordShowNewUserEmail' ) || $this->config->get( 'DiscordShowNewUserFullName' ) || $this->config->get( 'DiscordShowNewUserIP' ) ) {
			$messageExtra = '(';

			if ( $this->config->get( 'DiscordShowNewUserEmail' ) ) {
				$messageExtra .= $email . ', ';
			}

			if ( $this->config->get( 'DiscordShowNewUserFullName' ) ) {
				$messageExtra .= $realname . ', ';
			}

			if ( $this->config->get( 'DiscordShowNewUserIP' ) ) {
				$messageExtra .= $ipaddress . ', ';
			}

			// Remove trailing comma
			$messageExtra = substr( $messageExtra, 0, -2 );
			$messageExtra .= ')';
		}

		$message = self::msg( 'discordnotifications-new-user',
			$this->getDiscordUserText( $user ),
			$messageExtra
		);

		$this->discordNotifier->notify( $message, $user, 'new_user_account' );
	}

	/**
	 * @inheritDoc
	 */
	public function onUploadComplete( $uploadBase ) {
		if ( !$this->config->get( 'DiscordNotificationFileUpload' ) ) {
			return;
		}

		$localFile = $uploadBase->getLocalFile();

		$lang = RequestContext::getMain()->getLanguage();
		$user = RequestContext::getMain()->getUser();

		$message = self::msg( 'discordnotifications-file-uploaded',
			$this->getDiscordUserText( $user ),
			self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $localFile->getTitle()->getFullText() ),
			$localFile->getTitle()->getText(),
			$localFile->getMimeType(),
			$lang->formatSize( $localFile->getSize() ),
			'',
			$localFile->getDescription()
		);

		$this->discordNotifier->notify( $message, $user, 'file_uploaded' );
	}

	/**
	 * @inheritDoc
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		if ( !$this->config->get( 'DiscordNotificationBlockedUser' ) ) {
			return;
		}

		$reason = $block->getReasonComment()->text;

		$message = self::msg( 'discordnotifications-block-user',
			$this->getDiscordUserText( $user ),
			$this->getDiscordUserText(
				$block->getTargetUserIdentity() ?? $this->actorStore->getUnknownActor()
			),
			$reason == '' ? '' : self::msg( 'discordnotifications-block-user-reason' ) . " '" . $reason . "'.",
			$block->getExpiry(),
			'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockList' ) ) . '|' . self::msg( 'discordnotifications-block-user-list' ) . '>.'
		);

		$this->discordNotifier->notify( $message, $user, 'user_blocked' );
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGroupsChanged( $user, $added, $removed, $performer, $reason, $oldUGMs, $newUGMs ) {
		if ( !$this->config->get( 'DiscordNotificationUserGroupsChanged' ) ) {
			return;
		}

		$message = self::msg( 'discordnotifications-change-user-groups-with-old',
			$this->getDiscordUserText( $performer ),
			$this->getDiscordUserText( $user ),
			implode( ', ', array_keys( $oldUGMs ) ),
			implode( ', ', $this->userGroupManager->getUserGroups( $user ) ),
			'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserRights' ) . $this->getDiscordUserText( $performer ) ) . '|' . self::msg( 'discordnotifications-view-user-rights' ) . '>.'
		);

		$this->discordNotifier->notify( $message, $user, 'user_groups_changed' );
	}

	/**
	 * @param APIBase $module
	 */
	public function onAPIFlowAfterExecute( APIBase $module ) {
		if ( !$this->config->get( 'DiscordNotificationFlow' ) || !ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) {
			return;
		}

		$request = RequestContext::getMain()->getRequest();

		$action = $module->getModuleName();
		$request = $request->getValues();
		$result = $module->getResult()->getResultData()['flow'][$action];

		if ( $result['status'] != 'ok' ) {
			return;
		}

		if ( $this->titleIsExcluded( $request['page'] ) ) {
			return;
		}

		$user = RequestContext::getMain()->getUser();

		switch ( $action ) {
			case 'edit-header':
				$message = self::msg( 'discordnotifications-flow-edit-header',
					$this->getDiscordUserText( $user ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $request['page'] . '>'
				);

				break;
			case 'edit-post':
				$message = self::msg( 'discordnotifications-flow-edit-post',
					$this->getDiscordUserText( $user ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-title':
				$message = self::msg( 'discordnotifications-flow-edit-title',
					$this->getDiscordUserText( $user ),
					$request['etcontent'],
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-topic-summary':
				$message = self::msg( 'discordnotifications-flow-edit-topic-summary',
					$this->getDiscordUserText( $user ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'lock-topic':
				$message = self::msg( 'discordnotifications-flow-lock-topic',
					$this->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-lock-topic-lock
					// * discordnotifications-flow-lock-topic-unlock
					self::msg( 'discordnotifications-flow-lock-topic-' . $request['cotmoderationState'] ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'moderate-post':
				$message = self::msg( 'discordnotifications-flow-moderate-post',
					$this->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					self::msg( 'discordnotifications-flow-moderate-' . $request['mpmoderationState'] ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'moderate-topic':
				$message = self::msg( 'discordnotifications-flow-moderate-topic',
					$this->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					self::msg( 'discordnotifications-flow-moderate-' . $request['mtmoderationState'] ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'new-topic':
				$message = self::msg( 'discordnotifications-flow-new-topic',
					$this->getDiscordUserText( $user ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['committed']['topiclist']['topic-id'] ) . '|' . $request['nttopic'] . '>',
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $request['page'] . '>'
				);

				break;
			case 'reply':
				$message = self::msg( 'discordnotifications-flow-reply',
					$this->getDiscordUserText( $user ),
					'<' . self::parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			default:
				return;
		}

		$this->discordNotifier->notify( $message, $user, 'flow' );
	}

	/**
	 * @param string $key
	 * @param string ...$params
	 * @return string
	 */
	private static function msg( string $key, string ...$params ): string {
		if ( $params ) {
			return wfMessage( $key, ...$params )->inContentLanguage()->text();
		} else {
			return wfMessage( $key )->inContentLanguage()->text();
		}
	}

	/**
	 * @param string $UUID
	 * @return string
	 */
	private static function flowUUIDToTitleText( string $UUID ): string {
		$UUID = UUID::create( $UUID );
		$collection = PostCollection::newFromId( $UUID );
		$revision = $collection->getLastRevision();

		return $revision->getContent( 'topic-title-plaintext' );
	}
}
