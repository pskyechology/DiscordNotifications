<?php

declare( strict_types = 1 );

namespace Miraheze\DiscordNotifications\Hooks;

use Exception;
use ManualLogEntry;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Context\RequestContext;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Diff\TextDiffer\ManifoldTextDiffer;
use MediaWiki\Hook\AfterImportPageHook;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\ForeignTitle;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentityValue;
use Miraheze\DiscordNotifications\DiscordNotifier;
use TextSlotDiffRenderer;
use Wikimedia\IPUtils;

class Hooks implements
	AfterImportPageHook,
	ArticleProtectCompleteHook,
	BlockIpCompleteHook,
	LocalUserCreatedHook,
	PageDeleteCompleteHook,
	PageUndeleteCompleteHook,
	PageMoveCompleteHook,
	PageSaveCompleteHook,
	UploadCompleteHook,
	UserGroupsChangedHook
{

	private readonly Config $config;

	public function __construct(
		ConfigFactory $configFactory,
		private readonly DiscordNotifier $discordNotifier,
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManager $userGroupManager,
		private readonly WikiPageFactory $wikiPageFactory
	) {
		$this->config = $configFactory->makeConfig( 'DiscordNotifications' );
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		if ( $editResult->isNullEdit() ) {
			return;
		}

		$isNew = (bool)( $flags & EDIT_NEW );

		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['EditedArticle'] && !$isNew ) {
			return;
		}

		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['AddedArticle'] && $isNew ) {
			return;
		}

		// Do not announce newly added file uploads as articles...
		if ( $wikiPage->getTitle()->getNsText() && $wikiPage->getTitle()->getNsText() == $this->discordNotifier->getMessage( 'discordnotifications-file-namespace' ) ) {
			return;
		}

		$summary = strip_tags( $summary );

		$enableExperimentalCVTFeatures = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
				$this->config->get( 'DiscordExperimentalWebhook' );

		$content = '';
		$shouldSendToCVTFeed = false;
		$experimentalLanguageCode = '';
		if ( $enableExperimentalCVTFeatures ) {
			$content = $revisionRecord->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC ) ?? '';
			if ( $content ) {
				$content = strip_tags( $content->serialize() );
			}

			// Determine whether to send a message to the experimental CVT feed for the given user edit.
			// Checks if the configuration option to send all IP edits to the experimental CVT feed is enabled
			// and the user is not registered and their name is a valid IP address.
			// Also, checks that the user is not a bot, and if it is will not send to the experimental CVT feed.
			$shouldSendToCVTFeed = $this->config->get( 'DiscordExperimentalCVTSendAllIPEdits' ) &&
				( !$user->isRegistered() && IPUtils::isIPAddress( $user->getName() ) ) &&
				!$this->userFactory->newFromUserIdentity( $user )->isBot();

			$experimentalLanguageCode = $this->config->get( 'DiscordExperimentalFeedLanguageCode' );
		}

		if ( $isNew ) {
			if ( $enableExperimentalCVTFeatures ) {
				$regex = '/' . implode( '|', $this->config->get( 'DiscordExperimentalCVTMatchFilter' ) ) . '/';

				preg_match( $regex, $content, $matches, PREG_OFFSET_CAPTURE );

				if ( $matches || $shouldSendToCVTFeed ) {
					$message = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-article-created',
						$experimentalLanguageCode,
						$this->discordNotifier->getDiscordUserText( $user, $experimentalLanguageCode, true ),
						$this->discordNotifier->getDiscordArticleText( $wikiPage, false, $experimentalLanguageCode ),
						''
					);

					if ( $this->config->get( 'DiscordIncludeDiffSize' ) ) {
						$message .= ' (' . $this->discordNotifier->getMessageInLanguage( 'discordnotifications-bytes', $experimentalLanguageCode, sprintf( '%d', $revisionRecord->getSize() ) ) . ')';
					}

					if ( $matches ) {
						// The number of characters to show before and after the match
						$limit = $this->config->get( 'DiscordExperimentalCVTMatchLimit' );

						$start = ( $matches[0][1] - $limit > 0 ) ? $matches[0][1] - $limit : 0;
						$length = ( $matches[0][1] - $start ) + strlen( $matches[0][0] ) + $limit;
						$content = substr( $content, $start, $length );
					}

					$this->discordNotifier->notify( $message, $user, 'article_inserted', [
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-summary', $experimentalLanguageCode, '' ) => $summary,
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-content', $experimentalLanguageCode ) => $content ? "```\n$content\n```" : '',
					], $this->config->get( 'DiscordExperimentalWebhook' ), $wikiPage->getTitle() );
				}
			}

			$message = $this->discordNotifier->getMessage( 'discordnotifications-article-created',
				$this->discordNotifier->getDiscordUserText( $user ),
				$this->discordNotifier->getDiscordArticleText( $wikiPage ),
				$summary == '' ? '' : $this->discordNotifier->getMessageWithPlaintextParams( 'discordnotifications-summary', $summary )
			);

			if ( $this->config->get( 'DiscordIncludeDiffSize' ) ) {
				$message .= ' (' . $this->discordNotifier->getMessage( 'discordnotifications-bytes', sprintf( '%d', $revisionRecord->getSize() ) ) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_inserted', [], null, $wikiPage->getTitle() );
		} else {
			$isMinor = (bool)( $flags & EDIT_MINOR );

			// Skip minor edits if user wanted to ignore them
			if ( $isMinor && $this->config->get( 'DiscordIgnoreMinorEdits' ) ) {
				return;
			}

			if ( $enableExperimentalCVTFeatures ) {
				$regex = '/' . implode( '|', $this->config->get( 'DiscordExperimentalCVTMatchFilter' ) ) . '/';

				preg_match( $regex, $content, $matches, PREG_OFFSET_CAPTURE );

				if ( $matches || $shouldSendToCVTFeed ) {
					$message = $this->discordNotifier->getMessageInLanguage(
						'discordnotifications-article-saved',
						$experimentalLanguageCode,
						$this->discordNotifier->getDiscordUserText( $user, $experimentalLanguageCode, true ),
						$isMinor ? $this->discordNotifier->getMessageInLanguage( 'discordnotifications-article-saved-minor-edits', $experimentalLanguageCode ) : $this->discordNotifier->getMessageInLanguage( 'discordnotifications-article-saved-edit', $experimentalLanguageCode ),
						$this->discordNotifier->getDiscordArticleText( $wikiPage, true, $experimentalLanguageCode ),
						''
					);

					if (
						$this->config->get( 'DiscordIncludeDiffSize' ) &&
						$this->revisionLookup->getPreviousRevision( $revisionRecord )
					) {
						$message .= ' (' . $this->discordNotifier->getMessageInLanguage( 'discordnotifications-bytes', $experimentalLanguageCode,
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
					$textDiffer = new ManifoldTextDiffer(
						RequestContext::getMain(),
						null,
						$this->config->get( MainConfigNames::DiffEngine ),
						$this->config->get( MainConfigNames::ExternalDiffEngine ),
						$this->config->get( MainConfigNames::Wikidiff2Options )
					);

					$textSlotDiffRenderer->setFormat( 'table' );
					$textSlotDiffRenderer->setTextDiffer( $textDiffer );
					$textSlotDiffRenderer->setEngine( TextSlotDiffRenderer::ENGINE_PHP );

					$diff = $this->discordNotifier->getPlainDiff( $textSlotDiffRenderer->getTextDiff( $oldContent, $content ) );

					$this->discordNotifier->notify( $message, $user, 'article_saved', [
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-summary', $experimentalLanguageCode, '' ) => $summary,
						$this->discordNotifier->getMessageInLanguage( 'discordnotifications-content', $experimentalLanguageCode ) => $diff ? "```diff\n$diff\n```" : '',
					], $this->config->get( 'DiscordExperimentalWebhook' ), $wikiPage->getTitle() );
				}
			}

			$message = $this->discordNotifier->getMessage(
				'discordnotifications-article-saved',
				$this->discordNotifier->getDiscordUserText( $user ),
				$isMinor ? $this->discordNotifier->getMessage( 'discordnotifications-article-saved-minor-edits' ) : $this->discordNotifier->getMessage( 'discordnotifications-article-saved-edit' ),
				$this->discordNotifier->getDiscordArticleText( $wikiPage, true ),
				$summary == '' ? '' : $this->discordNotifier->getMessageWithPlaintextParams( 'discordnotifications-summary', $summary )
			);

			if (
				$this->config->get( 'DiscordIncludeDiffSize' ) &&
				$this->revisionLookup->getPreviousRevision( $revisionRecord )
			) {
				$message .= ' (' . $this->discordNotifier->getMessage( 'discordnotifications-bytes',
					sprintf( '%+d', $revisionRecord->getSize() - $this->revisionLookup->getPreviousRevision( $revisionRecord )->getSize() )
				) . ')';
			}

			$this->discordNotifier->notify( $message, $user, 'article_saved', [], null, $wikiPage->getTitle() );
		}
	}

	/**
	 * @inheritDoc
	 * @param int $pageID @phan-unused-param
	 * @param RevisionRecord $deletedRev @phan-unused-param
	 * @param int $archivedRevisionCount @phan-unused-param
	 */
	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['RemovedArticle'] ) {
			return;
		}

		if ( !$this->config->get( 'DiscordNotificationShowSuppressed' ) && $logEntry->getType() != 'delete' ) {
			return;
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $page );

		$message = $this->discordNotifier->getMessageWithPlaintextParams( 'discordnotifications-article-deleted',
			$this->discordNotifier->getDiscordUserText( $deleter->getUser() ),
			$this->discordNotifier->getDiscordArticleText( $wikiPage ),
			$reason
		);

		$this->discordNotifier->notify( $message, $deleter->getUser(), 'article_deleted', [], null, $wikiPage->getTitle() );
	}

	/**
	 * @inheritDoc
	 * @param RevisionRecord $restoredRev @phan-unused-param
	 * @param ManualLogEntry $logEntry @phan-unused-param
	 * @param int $restoredRevisionCount @phan-unused-param
	 * @param bool $created @phan-unused-param
	 * @param array $restoredPageIds @phan-unused-param
	 */
	public function onPageUndeleteComplete(	ProperPageIdentity $page, Authority $restorer, string $reason, RevisionRecord $restoredRev, ManualLogEntry $logEntry, int $restoredRevisionCount, bool $created, array $restoredPageIds ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['UnremovedArticle'] ) {
			return;
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $page );

		$message = $this->discordNotifier->getMessageWithPlaintextParams( 'discordnotifications-article-undeleted',
			$this->discordNotifier->getDiscordUserText( $restorer->getUser() ),
			$this->discordNotifier->getDiscordArticleText( $wikiPage ),
			$reason
		);

		$this->discordNotifier->notify( $message, $restorer->getUser(), 'article_undeleted', [], null, $wikiPage->getTitle() );
	}

	/**
	 * @inheritDoc
	 * @param int $pageid @phan-unused-param
	 * @param int $redirid @phan-unused-param
	 * @param RevisionRecord $revision @phan-unused-param
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['MovedArticle'] ) {
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

	/** @inheritDoc */
	public function onArticleProtectComplete( $wikiPage, $user, $protect, $reason ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['ProtectedArticle'] ) {
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
	 * @param ForeignTitle $foreignTitle @phan-unused-param
	 * @param int $revCount @phan-unused-param
	 * @param array $pageInfo @phan-unused-param
	 */
	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['AfterImportPage'] ) {
			return;
		}

		if ( $sRevCount == 0 ) {
			return;
		}

		$message = $this->discordNotifier->getMessage( 'discordnotifications-import-complete',
			$this->discordNotifier->getDiscordTitleText( $title )
		);

		$this->discordNotifier->notify( $message, null, 'import_complete' );
	}

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['NewUser'] ) {
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
		} catch ( Exception ) {
		}

		try {
			$realname = $user->getRealName();
		} catch ( Exception ) {
		}

		try {
			$ipaddress = $user->getRequest()->getIP();
		} catch ( Exception ) {
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
				$messageInLanguage = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-new-user', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
					$this->discordNotifier->getDiscordUserText( $user, $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), true ),
					$messageExtra
				);

				if ( $this->config->get( 'DiscordExperimentalCVTUsernameFilter' ) && $this->discordNotifier->isOffensiveUsername( $user->getName() ) ) {
					$messageInLanguage = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-new-user-filtered', $this->config->get( 'DiscordExperimentalFeedLanguageCode' ),
						$this->discordNotifier->getDiscordUserText( $user, $this->config->get( 'DiscordExperimentalFeedLanguageCode' ), true ),
						$messageExtra
					);
				}
			}

			$this->discordNotifier->notify( $messageInLanguage ?? $message, $user, 'new_user_account', [], $webhook );
		}

		if ( $webhook || $autocreated ) {
			$this->discordNotifier->notify( $message, $user, 'new_user_account' );
		}
	}

	/** @inheritDoc */
	public function onUploadComplete( $uploadBase ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['FileUpload'] ) {
			return;
		}
		$showImage = ( $this->config->get( 'DiscordNotificationShowImage' ) );

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
			strip_tags( $localFile->getDescription() )
		);

		$this->discordNotifier->notify( $message, $user, 'file_uploaded', imageUrl: $localFile->getFullUrl() ?: $showImage );
	}

	/**
	 * @inheritDoc
	 * @param ?DatabaseBlock $priorBlock @phan-unused-param
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['BlockedUser'] ) {
			return;
		}

		$reason = $block->getReasonComment()->text;

		$message = $this->discordNotifier->getMessage( 'discordnotifications-block-user',
			$this->discordNotifier->getDiscordUserText( $user ),
			$this->discordNotifier->getDiscordUserText(
				$block->getTargetUserIdentity() ?? UserIdentityValue::newAnonymous( $block->getTargetName() )
			),
			$reason == '' ? '' : $this->discordNotifier->getMessage( 'discordnotifications-block-user-reason' ) . " '" . $reason . "'.",
			$block->getExpiry() === 'infinity' ? 'infinity' : '<t:' . wfTimestamp( TS_UNIX, $block->getExpiry() ) . ':F>',
			'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockList' ) ) . '|' . $this->discordNotifier->getMessage( 'discordnotifications-block-user-list' ) . '>.'
		);

		$webhook = $this->config->get( 'DiscordEnableExperimentalCVTFeatures' ) &&
			$this->config->get( 'DiscordExperimentalCVTSendAllUserBlocks' ) ?
			$this->config->get( 'DiscordExperimentalWebhook' ) :
			( $this->config->get( 'DiscordExperimentalUserBlocksWebhook' ) ?: null );

		if ( $webhook ) {
			$experimentalLanguageCode = $this->config->get( 'DiscordExperimentalFeedLanguageCode' );
			if ( $experimentalLanguageCode ) {
				$messageInLanguage = $this->discordNotifier->getMessageInLanguage( 'discordnotifications-block-user',
					$experimentalLanguageCode,
					$this->discordNotifier->getDiscordUserText( $user, $experimentalLanguageCode ),
					$this->discordNotifier->getDiscordUserText(
						$block->getTargetUserIdentity() ?? UserIdentityValue::newAnonymous( $block->getTargetName() ),
						$experimentalLanguageCode, true
					),
					$reason == '' ? '' : $this->discordNotifier->getMessageInLanguage( 'discordnotifications-block-user-reason', $experimentalLanguageCode ) . " '" . $reason . "'.",
					$block->getExpiry() === 'infinity' ? 'infinity' : '<t:' . wfTimestamp( TS_UNIX, $block->getExpiry() ) . ':F>',
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $this->config->get( 'DiscordNotificationWikiUrlEndingBlockList' ) ) . '|' . $this->discordNotifier->getMessageInLanguage( 'discordnotifications-block-user-list', $experimentalLanguageCode ) . '>.'
				);
			}

			$this->discordNotifier->notify( $messageInLanguage ?? $message, $user, 'user_blocked', [], $webhook );
		}

		$this->discordNotifier->notify( $message, $user, 'user_blocked' );
	}

	/**
	 * @inheritDoc
	 * @param string[] $added @phan-unused-param
	 * @param string[] $removed @phan-unused-param
	 * @param string|false $reason @phan-unused-param
	 * @param array $newUGMs @phan-unused-param
	 */
	public function onUserGroupsChanged( $user, $added, $removed, $performer, $reason, $oldUGMs, $newUGMs ): void {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['UserGroupsChanged'] ) {
			return;
		}

		if ( $user->getWikiId() !== WikiAwareEntity::LOCAL ) {
			// TODO: Support external users
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
}
