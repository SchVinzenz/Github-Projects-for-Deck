<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\BackgroundJob;

use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class SyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private BoardMapMapper $maps,
		private SyncService $sync,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
		$interval = (int)$this->config->getAppValue('deckgithubsync', 'sync_interval', '900');
		$this->setInterval(max(300, $interval));
	}

	#[\Override]
	protected function run($argument): void {
		$due = time() - max(300, (int)$this->config->getAppValue('deckgithubsync', 'sync_interval', '900'));
		foreach ($this->maps->findAllDue($due) as $map) {
			try {
				$this->sync->syncBoard($map);
			} catch (\Throwable $e) {
				$this->logger->warning('deckgithubsync background sync failed', ['exception' => $e]);
			}
		}
	}
}
