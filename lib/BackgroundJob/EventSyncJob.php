<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\BackgroundJob;

use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Service\SyncService;
use OCA\DeckGithubSync\Service\SyncQueueService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class EventSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private BoardMapMapper $maps,
		private SyncService $sync,
		private SyncQueueService $queue,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$map = $this->maps->findById((int)$argument);
		} catch (DoesNotExistException) {
			return; // Mapping was removed after the event was queued.
		}
		if ($map->getCooldownUntil() > time()) {
			return;
		}
		try {
			$result = $this->sync->syncBoard($map);
			if ($result['errors'] !== []) {
				$this->logger->warning('deckgithubsync event sync failed', ['map' => $map->getId(), 'errors' => $result['errors']]);
			}
		} catch (LockedException) {
			$this->queue->queueMap($map);
		} catch (\Throwable $e) {
			$this->logger->warning('deckgithubsync event sync failed', ['map' => $map->getId(), 'exception' => $e]);
		}
	}
}
