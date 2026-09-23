<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Controller;

use OCA\DeckGithubSync\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class SyncController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $sync,
		private ?string $userId,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function trigger(int $id): DataResponse {
		try {
			$map = $this->sync->findMap($id, $this->userId ?? '');
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse($this->sync->syncBoard($map));
		} catch (LockedException) {
			return new DataResponse(['busy' => true], Http::STATUS_ACCEPTED);
		} catch (\Throwable $e) {
			$this->logger->error('deckgithubsync manual sync failed', ['map' => $id, 'exception' => $e]);
			return new DataResponse(['error' => 'Synchronization failed. Check the Nextcloud server log.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function status(int $id): DataResponse {
		try {
			$map = $this->sync->findMap($id, $this->userId ?? '');
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['lastSync' => $map->getLastSync(), 'direction' => $map->getDirection()]);
	}
}
