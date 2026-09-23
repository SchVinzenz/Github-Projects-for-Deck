<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

use OCA\DeckGithubSync\BackgroundJob\EventSyncJob;
use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCP\BackgroundJob\IJobList;

/** Coalesce changes for a mapping into one short-delayed background job. */
class SyncQueueService {
	private const DEBOUNCE_SECONDS = 5;

	public function __construct(
		private BoardMapMapper $maps,
		private IJobList $jobs,
	) {
	}

	public function queueBoard(int $boardId): void {
		foreach ($this->maps->findByDeckBoard($boardId) as $map) {
			if ($map->getDirection() !== BoardMap::DIR_TO_DECK) {
				$this->queueMap($map);
			}
		}
	}

	public function queueMap(BoardMap $map): void {
		if ($map->getCooldownUntil() > time()) {
			return;
		}
		// Keep the periodic job eligible as a fallback if this one-time job fails.
		if ($map->getLastSync() !== 0) {
			$map->setLastSync(0);
			$this->maps->update($map);
		}
		// Nextcloud updates an existing job with the same class and argument.
		$this->jobs->scheduleAfter(EventSyncJob::class, time() + self::DEBOUNCE_SECONDS, (int)$map->getId());
	}
}
