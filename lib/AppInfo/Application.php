<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\AppInfo;

use OCA\DeckGithubSync\Listener\DeckChangeListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'deckgithubsync';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener('OCA\\Deck\\Event\\CardCreatedEvent', DeckChangeListener::class);
		$context->registerEventListener('OCA\\Deck\\Event\\CardUpdatedEvent', DeckChangeListener::class);
		$context->registerEventListener('OCA\\Deck\\Event\\CardDeletedEvent', DeckChangeListener::class);
		$context->registerEventListener('OCA\\Deck\\Event\\BoardUpdatedEvent', DeckChangeListener::class);
		$context->registerEventListener('OCP\\Comments\\Events\\CommentAddedEvent', DeckChangeListener::class);
		$context->registerEventListener('OCP\\Comments\\Events\\CommentUpdatedEvent', DeckChangeListener::class);
		$context->registerEventListener('OCP\\Comments\\CommentsEvent', DeckChangeListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
