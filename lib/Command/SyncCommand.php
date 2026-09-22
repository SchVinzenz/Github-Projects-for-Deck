<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Command;

use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command {
	protected function configure(): void {
		$this->setName('deckgithubsync:sync')
			->setDescription('Sync Deck boards with GitHub Projects')
			->addArgument('map-id', InputArgument::OPTIONAL, 'Board map ID (omit for all due)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$maps = Server::get(BoardMapMapper::class);
		$sync = Server::get(SyncService::class);
		$mapId = $input->getArgument('map-id');
		if ($mapId !== null) {
			$map = $maps->find((int)$mapId);
			$stats = $sync->syncBoard($map);
			$output->writeln(json_encode($stats));
			return 0;
		}
		foreach ($maps->findAllDue(time() - 1) as $map) {
			$stats = $sync->syncBoard($map);
			$output->writeln("map {$map->getId()}: " . json_encode($stats));
		}
		return 0;
	}
}
