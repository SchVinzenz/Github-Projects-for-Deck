<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Controller;

use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;

class SyncController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private BoardMapMapper $maps,
		private SyncService $sync,
		private ?string $userId,
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
		$stats = $this->sync->syncBoard($map);
		return new DataResponse($stats);
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
