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
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\ActorStore;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\UserGroupManager;
use RequestContext;
use TextSlotDiffRenderer;
use TitleFactory;

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

		if ( $this->discordNotifier->titleIsExcluded( $wikiPage->getTitle()->getText() ) ) {
			return;
		}

		// Do not announce newly added file uploads as articles...
		if ( $wikiPage->getTitle()->getNsText() && $wikiPage->getTitle()->getNsText() == $this->discordNotifier->getMessage( 'discordnotifications-file-namespace' ) ) {
			return;
		}

		$enableExperimentalCVTFeatures = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
				$this->config->get( 'DiscordExperimentalWebhook' );

		$content = '';
		$shouldSendToCVTFeed = false;
		if ( $enableExperimentalCVTFeatures ) {
			$content = $revisionRecord->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC ) ?? '';
			if ( $content ) {
				$content = strip_tags( $content->serialize() );
			}

			$shouldSendToCVTFeed = $this->config->get( 'DiscordExperimentalCVTSendAllIPEdits' ) && !$user->isRegistered();
		}

		if ( $isNew ) {
			if ( $enableExperimentalCVTFeatures ) {
				$regex = '/' . implode( '|', $this->config->get( 'DiscordExperimentalCVTMatchFilter' ) ) . '/';

				// @phan-suppress-next-line SecurityCheck-LikelyFalsePositive
				preg_match( $regex, $content, $matches, PREG_OFFSET_CAPTURE );

				if ( $matches || $shouldSendToCVTFeed ) {
					$message = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-article-created',
						$this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
						$this->discordNotifier->getDiscordUserText( $user ),
						$this->discordNotifier->getDiscordArticleText( $wikiPage ),
						''
					);

					if ( $this->config->get( 'DiscordIncludeDiffSize' ) ) {
						$message .= ' (' . $this->discordNotifier->getMessageInLanguage( 'discordnotifications-bytes', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), sprintf( '%d', $revisionRecord->getSize() ) ) . ')';
					}

					if ( $matches ) {
						// The number of characters to show before and after the match
						$limit = $this->config->get( 'DiscordExperimentalCVTMatchLimit' );

						$start = ( $matches[0][1] - $limit > 0 ) ? $matches[0][1] - $limit : 0;
						$length = ( $matches[0][1] - $start ) + strlen( $matches[0][0] ) + $limit;
						$content = substr( $content, $start, $length );
					}

					$this->discordNotifier->notify( $message, $user, 'article_inserted', [
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-summary', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), '' ) => $summary,
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-content', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ) ) => $content ? "```\n$content\n```" : '',
					], $this->config->get( 'DiscordExperimentalWebhook' ) );
				}
			}

			$message = $this->discordNotifier->getMessage( 'discordnotifications-article-created',
				$this->discordNotifier->getDiscordUserText( $user ),
				$this->discordNotifier->getDiscordArticleText( $wikiPage ),
				$summary == '' ? '' : wfMessage( 'discordnotifications-summary' )->plaintextParams( $summary )->inContentLanguage()->text()
			);

			if ( $this->config->get( 'DiscordIncludeDiffSize' ) ) {
				$message .= ' (' . $this->discordNotifier->getMessage( 'discordnotifications-bytes', sprintf( '%d', $revisionRecord->getSize() ) ) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_inserted' );
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );

			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $this->config->get( 'DiscordIgnoreMinorEdits' ) ) {
				return;
			}

			if ( $enableExperimentalCVTFeatures ) {
				$regex = '/' . implode( '|', $this->config->get( 'DiscordExperimentalCVTMatchFilter' ) ) . '/';

				// @phan-suppress-next-line SecurityCheck-LikelyFalsePositive
				preg_match( $regex, $content, $matches, PREG_OFFSET_CAPTURE );

				if ( $matches || $shouldSendToCVTFeed ) {
					$message = $this->discordNotifier->getMessageInLanguage(
						'discordnotifications-article-saved',
						$this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
						$this->discordNotifier->getDiscordUserText( $user ),
						$isMinor ? $this->discordNotifier->getMessage( 'discordnotifications-article-saved-minor-edits' ) : $this->discordNotifier->getMessage( 'discordnotifications-article-saved-edit' ),
						$this->discordNotifier->getDiscordArticleText( $wikiPage, true ),
						''
					);

					if (
						$this->config->get( 'DiscordIncludeDiffSize' ) &&
						$this->revisionLookup->getPreviousRevision( $revisionRecord )
					) {
						$message .= ' (' . $this->discordNotifier->getMessageInLanguage( 'discordnotifications-bytes', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
							sprintf( '%+d', $revisionRecord->getSize() - $this->revisionLookup->getPreviousRevision( $revisionRecord )->getSize() )
						) . ')';
					}

					$oldContent = ( $this->revisionLookup->getPreviousRevision( $revisionRecord ) ?
						$this->revisionLookup->getPreviousRevision( $revisionRecord )
							->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC ) : null ) ?? '';

					if ( $oldContent ) {
						$oldContent = strip_tags( $oldContent->serialize() );
					}

					if ( $matches ) {
						// The number of characters to show before and after the match
						$limit = $this->config->get( 'DiscordExperimentalCVTMatchLimit' );

						$start = ( $matches[0][1] - $limit > 0 ) ? $matches[0][1] - $limit : 0;
						$length = ( $matches[0][1] - $start ) + strlen( $matches[0][0] ) + $limit;
						$content = substr( $content, $start, $length );
					}

					$textSlotDiffRenderer = new TextSlotDiffRenderer();
					$this->discordNotifier->notify( $message, $user, 'article_saved', [
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-summary', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), '' ) => $summary,
						$this->discordNotifier->getMessage( 'discordnotifications-content', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ) ) => "```diff\n" . $this->getPlainDiff( $textSlotDiffRenderer->getTextDiff( $oldContent, $content ) ) . "\n```",
					], $this->config->get( 'DiscordExperimentalWebhook' ) );
				}
			}

			$message = $this->discordNotifier->getMessage(
				'discordnotifications-article-saved',
				$this->discordNotifier->getDiscordUserText( $user ),
				$isMinor ? $this->discordNotifier->getMessage( 'discordnotifications-article-saved-minor-edits' ) : $this->discordNotifier->getMessage( 'discordnotifications-article-saved-edit' ),
				$this->discordNotifier->getDiscordArticleText( $wikiPage, true ),
				$summary == '' ? '' : wfMessage( 'discordnotifications-summary' )->plaintextParams( $summary )->inContentLanguage()->text()
			);

			if (
				$this->config->get( 'DiscordIncludeDiffSize' ) &&
				$this->revisionLookup->getPreviousRevision( $revisionRecord )
			) {
				$message .= ' (' . $this->discordNotifier->getMessage( 'discordnotifications-bytes',
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

		if ( $this->discordNotifier->titleIsExcluded( $wikiPage->getTitle()->getText() ) ) {
			return;
		}

		$message = wfMessage( 'discordnotifications-article-deleted' )->plaintextParams(
			$this->discordNotifier->getDiscordUserText( $deleter->getUser() ),
			$this->discordNotifier->getDiscordArticleText( $wikiPage ),
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-article-moved',
			$this->discordNotifier->getDiscordUserText( $user ),
			$this->discordNotifier->getDiscordTitleText( $this->titleFactory->newFromLinkTarget( $old ) ),
			$this->discordNotifier->getDiscordTitleText( $this->titleFactory->newFromLinkTarget( $new ) ),
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-article-protected',
			$this->discordNotifier->getDiscordUserText( $user ),
			$protect ? $this->discordNotifier->getMessage( 'discordnotifications-article-protected-change' ) : $this->discordNotifier->getMessage( 'discordnotifications-article-protected-remove' ),
			$this->discordNotifier->getDiscordArticleText( $wikiPage ),
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-import-complete',
			$this->discordNotifier->getDiscordTitleText( $title )
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

		if ( !$this->config->get( 'DiscordNotificationIncludeAutocreatedUsers' ) && $autocreated ) {
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-new-user',
			$this->discordNotifier->getDiscordUserText( $user ),
			$messageExtra
		);

		$webhook = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
			$this->config->get( 'DiscordExperimentalCVTSendAllNewUsers' ) ?
			$this->config->get( 'DiscordExperimentalWebhook' ) :
			( $this->config->get( 'DiscordExperimentalNewUsersWebhook' ) ?: null );

		if ( !$autocreated ) {
			if ( $webhook && $this->config->get( 'DiscordExperimentalFeedLanguageCode' ) ) {
				$message = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-new-user', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
					$this->discordNotifier->getDiscordUserText( $user ),
					$messageExtra
				);
			}

			$this->discordNotifier->notify( $message, $user, 'new_user_account', [], $webhook );
		}

		if ( $webhook || $autocreated ) {
			$this->discordNotifier->notify( $message, $user, 'new_user_account' );
		}
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-file-uploaded',
			$this->discordNotifier->getDiscordUserText( $user ),
			$this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $localFile->getTitle()->getFullText() ),
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-block-user',
			$this->discordNotifier->getDiscordUserText( $user ),
			$this->discordNotifier->getDiscordUserText(
				$block->getTargetUserIdentity() ?? $this->actorStore->getUnknownActor()
			),
			$reason == '' ? '' : $this->discordNotifier->getMessage( 'discordnotifications-block-user-reason' ) . " '" . $reason . "'.",
			$block->getExpiry(),
			'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockList' ) ) . '|' . $this->discordNotifier->getMessage( 'discordnotifications-block-user-list' ) . '>.'
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

		$message = $this->discordNotifier->getMessage( 'discordnotifications-change-user-groups-with-old',
			$this->discordNotifier->getDiscordUserText( $performer ),
			$this->discordNotifier->getDiscordUserText( $user ),
			implode( ', ', array_keys( $oldUGMs ) ),
			implode( ', ', $this->userGroupManager->getUserGroups( $user ) ),
			'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingUserRights' ) . $this->discordNotifier->getDiscordUserText( $performer ) ) . '|' . $this->discordNotifier->getMessage( 'discordnotifications-view-user-rights' ) . '>.'
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

		if ( $this->discordNotifier->titleIsExcluded( $request['page'] ) ) {
			return;
		}

		$user = RequestContext::getMain()->getUser();

		switch ( $action ) {
			case 'edit-header':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-header',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $request['page'] . '>'
				);

				break;
			case 'edit-post':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-post',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-title':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-title',
					$this->discordNotifier->getDiscordUserText( $user ),
					$request['etcontent'],
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-topic-summary':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-topic-summary',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'lock-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-lock-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-lock-topic-lock
					// * discordnotifications-flow-lock-topic-unlock
					$this->discordNotifier->getMessage( 'discordnotifications-flow-lock-topic-' . $request['cotmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'moderate-post':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-post',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					$this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-' . $request['mpmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'moderate-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-moderate-hide
					// * discordnotifications-flow-moderate-unhide
					// * discordnotifications-flow-moderate-suppress
					// * discordnotifications-flow-moderate-unsuppress
					// * discordnotifications-flow-moderate-delete
					// * discordnotifications-flow-moderate-undelete
					$this->discordNotifier->getMessage( 'discordnotifications-flow-moderate-' . $request['mtmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'new-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-new-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['committed']['topiclist']['topic-id'] ) . '|' . $request['nttopic'] . '>',
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $request['page'] . '>'
				);

				break;
			case 'reply':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-reply',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . self::flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			default:
				return;
		}

		$this->discordNotifier->notify( $message, $user, 'flow' );
	}

	/**
	 * Convert the HTML diff to a human-readable format so it can be in the Discord embed
	 *
	 * @param string $diff
	 * @return string
	 */
	private function getPlainDiff( string $diff ): string {
		$replacements = [
			html_entity_decode( '&nbsp;' ) => ' ',
			html_entity_decode( '&minus;' ) => '-',
			'+' => "\n+",
		];

		// Preserve markers when stripping tags
		$diff = str_replace( '<td class="diff-marker"></td>', ' ', $diff );
		$diff = preg_replace( '@<td colspan="2"( class="(?:diff-side-deleted|diff-side-added)")?></td>@', "\n\n", $diff );
		$diff = preg_replace( '/data-marker="([^"]*)">/', '>$1', $diff );

		return str_replace( array_keys( $replacements ), array_values( $replacements ),
			strip_tags( $diff ) );
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
