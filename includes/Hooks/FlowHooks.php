<?php

use Flow\Hooks\APIFlowAfterExecuteHook;
use MediaWiki\Api\ApiBase;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Context\RequestContext;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use Miraheze\DiscordNotifications\DiscordNotifier;

class FlowHooks implements APIFlowAfterExecuteHook {
	private readonly Config $config;

	public function __construct(
		ConfigFactory $configFactory,
		private readonly DiscordNotifier $discordNotifier,
	) {
		$this->config = $configFactory->makeConfig( 'DiscordNotifications' );
	}

	public function onAPIFlowAfterExecute( APIBase $module ) {
		if ( !$this->config->get( 'DiscordNotificationEnabledActions' )['Flow'] || !ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) {
			return;
		}

		$request = RequestContext::getMain()->getRequest();

		$action = $module->getModuleName();
		$request = $request->getValues();
		$result = $module->getResult()->getResultData()['flow'][$action];

		if ( $result['status'] != 'ok' ) {
			return;
		}

		$title = Title::newFromText( $request['page'] );
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
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-title':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-title',
					$this->discordNotifier->getDiscordUserText( $user ),
					$request['etcontent'],
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'edit-topic-summary':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-edit-topic-summary',
					$this->discordNotifier->getDiscordUserText( $user ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			case 'lock-topic':
				$message = $this->discordNotifier->getMessage( 'discordnotifications-flow-lock-topic',
					$this->discordNotifier->getDiscordUserText( $user ),
					// Messages that can be used here:
					// * discordnotifications-flow-lock-topic-lock
					// * discordnotifications-flow-lock-topic-unlock
					$this->discordNotifier->getMessage( 'discordnotifications-flow-lock-topic-' . $request['cotmoderationState'] ),
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
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
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
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
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . $request['page'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
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
					'<' . $this->discordNotifier->parseurl( $this->config->get( 'DiscordNotificationWikiUrl' ) . $this->config->get( 'DiscordNotificationWikiUrlEnding' ) . 'Topic:' . $result['workflow'] ) . '|' . $this->discordNotifier->flowUUIDToTitleText( $result['workflow'] ) . '>'
				);

				break;
			default:
				return;
		}

		$this->discordNotifier->notify( $message, $user, 'flow', [], null, $title );
	}
}
