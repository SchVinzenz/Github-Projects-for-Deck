<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Listener;

use OCA\Deck\Event\ACardEvent;
use OCA\Deck\Event\BoardUpdatedEvent;
use OCA\Deck\Event\CardUpdatedEvent;
use OCA\DeckGithubSync\Service\SyncQueueService;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\Comments\CommentsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Server;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<Event> */
class DeckChangeListener implements IEventListener {
	public function __construct(
		private SyncQueueService $queue,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (SyncService::isRunning()) {
			return; // Deck writes made by the sync must not trigger another sync.
		}
		try {
			if ($event instanceof BoardUpdatedEvent) {
				$this->queue->queueBoard($event->getBoardId());
			} elseif ($event instanceof ACardEvent) {
				$this->queueCardStack($event->getCard()->getStackId());
				if ($event instanceof CardUpdatedEvent && $event->getCardBefore() !== null
					&& $event->getCardBefore()->getStackId() !== $event->getCard()->getStackId()) {
					$this->queueCardStack($event->getCardBefore()->getStackId());
				}
			} elseif ($event instanceof CommentsEvent && $event->getComment()->getObjectType() === 'deckCard') {
				$card = Server::get('OCA\\Deck\\Db\\CardMapper')->find((int)$event->getComment()->getObjectId(), false);
				$this->queueCardStack($card->getStackId());
			}
		} catch (\Throwable $e) {
			// An event listener must not make a Deck edit fail. The periodic sync remains available.
			$this->logger->warning('deckgithubsync could not queue Deck change', ['exception' => $e]);
		}
	}

	private function queueCardStack(int $stackId): void {
		$stack = Server::get('OCA\\Deck\\Db\\StackMapper')->find($stackId);
		$this->queue->queueBoard($stack->getBoardId());
	}
}
