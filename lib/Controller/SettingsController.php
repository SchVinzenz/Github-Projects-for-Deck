<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Controller;

use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Service\GithubProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private BoardMapMapper $maps,
		private GithubProjectService $projects,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** @NoAdminRequired */
	public function listMappings(): DataResponse {
		$maps = $this->maps->findByUser($this->userId ?? '');
		return new DataResponse(array_map(fn ($m) => $this->serialize($m), $maps));
	}

	/** @NoAdminRequired */
	public function createMapping(int $deckBoardId, string $githubOwner, int $githubNumber, string $direction = 'both', array $fieldConfig = []): DataResponse {
		if (!in_array($direction, ['both', 'deck_to_github', 'github_to_deck'], true)) {
			return new DataResponse(['error' => 'Invalid direction'], Http::STATUS_BAD_REQUEST);
		}
		$projectId = $this->projects->resolveProjectId($this->userId ?? '', $githubOwner, $githubNumber)
			?? $this->projects->resolveProjectId($this->userId ?? '', $githubOwner, $githubNumber, 'user');
		if ($projectId === null) {
			return new DataResponse(['error' => 'GitHub project not found'], Http::STATUS_BAD_REQUEST);
		}
		$fields = $this->projects->getFields($this->userId ?? '', $projectId);
		$map = new BoardMap();
		$map->setUserId($this->userId ?? '');
		$map->setDeckBoardId($deckBoardId);
		$map->setGithubProjectId($projectId);
		$map->setGithubOwner($githubOwner);
		$map->setGithubNumber($githubNumber);
		$map->setDirection($direction);
		$map->setFieldConfig((string)json_encode($fieldConfig));
		$map->setStatusFieldId($fields['statusFieldId']);
		$map->setLastSync(0);
		$this->maps->insert($map);
		return new DataResponse($this->serialize($map), Http::STATUS_CREATED);
	}

	/** @NoAdminRequired */
	public function updateMapping(int $id, ?string $direction = null, ?array $fieldConfig = null): DataResponse {
		try {
			$map = $this->maps->find($id);
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($map->getUserId() !== $this->userId) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($direction !== null) {
			$map->setDirection($direction);
		}
		if ($fieldConfig !== null) {
			$map->setFieldConfig((string)json_encode($fieldConfig));
		}
		$this->maps->update($map);
		return new DataResponse($this->serialize($map));
	}

	/** @NoAdminRequired */
	public function deleteMapping(int $id): DataResponse {
		try {
			$map = $this->maps->find($id);
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($map->getUserId() !== $this->userId) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$this->maps->delete($map);
		return new DataResponse([]);
	}

	public function getAdmin(): DataResponse {
		return new DataResponse([
			'github_app_id' => $this->config->getAppValue('deckgithubsync', 'github_app_id', ''),
			'github_installation_id' => $this->config->getAppValue('deckgithubsync', 'github_installation_id', ''),
			'has_private_key' => $this->config->getAppValue('deckgithubsync', 'github_private_key', '') !== '',
			'has_webhook_secret' => $this->config->getAppValue('deckgithubsync', 'webhook_secret', '') !== '',
			'sync_interval' => (int)$this->config->getAppValue('deckgithubsync', 'sync_interval', '900'),
		]);
	}

	public function setAdmin(string $githubAppId = '', string $githubInstallationId = '', string $githubPrivateKey = '', string $webhookSecret = '', int $syncInterval = 900): DataResponse {
		$this->config->setAppValue('deckgithubsync', 'github_app_id', $githubAppId);
		$this->config->setAppValue('deckgithubsync', 'github_installation_id', $githubInstallationId);
		if ($githubPrivateKey !== '') {
			$this->config->setAppValue('deckgithubsync', 'github_private_key', $githubPrivateKey);
		}
		if ($webhookSecret !== '') {
			$this->config->setAppValue('deckgithubsync', 'webhook_secret', $webhookSecret);
		}
		$this->config->setAppValue('deckgithubsync', 'sync_interval', (string)max(300, $syncInterval));
		return $this->getAdmin();
	}

	private function serialize(BoardMap $m): array {
		return [
			'id' => $m->getId(),
			'deckBoardId' => $m->getDeckBoardId(),
			'githubOwner' => $m->getGithubOwner(),
			'githubNumber' => $m->getGithubNumber(),
			'direction' => $m->getDirection(),
			'fieldConfig' => $m->getFieldMap(),
			'lastSync' => $m->getLastSync(),
		];
	}
}
