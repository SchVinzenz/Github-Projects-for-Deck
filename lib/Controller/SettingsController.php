<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Controller;

use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Db\ItemMapMapper;
use OCA\DeckGithubSync\Db\UserMap;
use OCA\DeckGithubSync\Db\UserMapMapper;
use OCA\DeckGithubSync\Service\DeckService;
use OCA\DeckGithubSync\Service\GithubClientService;
use OCA\DeckGithubSync\Service\GithubProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;

class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IDBConnection $db,
		private BoardMapMapper $maps,
		private ItemMapMapper $itemMaps,
		private UserMapMapper $userMaps,
		private GithubProjectService $projects,
		private GithubClientService $github,
		private DeckService $deck,
		private IURLGenerator $urls,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listMappings(): DataResponse {
		$maps = $this->maps->findByUser($this->userId ?? '');
		return new DataResponse(array_map(fn ($m) => $this->serialize($m), $maps));
	}

	#[NoAdminRequired]
	public function mappingDateFields(int $id): DataResponse {
		try {
			$map = $this->maps->findById($id);
			if ($map->getUserId() !== $this->userId) {
				return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}
			$fields = $this->projects->getFields($this->userId ?? '', $map->getGithubProjectId());
			return new DataResponse(array_values(array_map(
				static fn (array $f): array => ['id' => $f['id'], 'name' => $f['name']],
				array_filter($fields['fields'] ?? [], static fn (array $f): bool => ($f['dataType'] ?? '') === 'DATE' && isset($f['id'], $f['name']))
			)));
		} catch (\Throwable) {
			return new DataResponse(['error' => 'Datumsfelder konnten nicht geladen werden.'], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function createMapping(int $deckBoardId, string $githubOwner, int $githubNumber, string $direction = 'both', array $fieldConfig = [], string $githubRepository = ''): DataResponse {
		if (!in_array($direction, ['both', 'deck_to_github', 'github_to_deck'], true)) {
			return new DataResponse(['error' => 'Invalid direction'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->deck->getStacks($this->userId ?? '', $deckBoardId);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => 'Deck-Board nicht gefunden oder kein Zugriff.'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$projectId = $this->projects->resolveProjectId($this->userId ?? '', $githubOwner, $githubNumber);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => 'GitHub Project konnte nicht geprüft werden. Bitte Zugriff und Project-Berechtigungen prüfen.'], Http::STATUS_BAD_GATEWAY);
		}
		if ($projectId === null) {
			return new DataResponse(['error' => 'GitHub Project wurde nicht gefunden. Owner und Project-Nummer prüfen.'], Http::STATUS_BAD_REQUEST);
		}
		if ($githubRepository !== '') {
			try {
				$this->projects->getRepositoryNodeId($this->userId ?? '', $githubRepository);
			} catch (\Throwable) {
				return new DataResponse(['error' => 'Das Issue-Repository ist nicht erreichbar oder kann keine Issues erstellen.'], Http::STATUS_BAD_REQUEST);
			}
		}
		foreach ($this->maps->findByUser($this->userId ?? '') as $existing) {
			if ($existing->getDeckBoardId() === $deckBoardId && $existing->getGithubProjectId() === $projectId) {
				return new DataResponse(['error' => 'Für dieses Deck-Board und GitHub Project gibt es bereits ein Mapping.'], Http::STATUS_CONFLICT);
			}
		}
		try {
			$fields = $this->projects->getFields($this->userId ?? '', $projectId);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => 'GitHub-Project konnte nicht gelesen werden.'], Http::STATUS_BAD_GATEWAY);
		}
		$map = new BoardMap();
		$map->setUserId($this->userId ?? '');
		$map->setDeckBoardId($deckBoardId);
		$map->setGithubProjectId($projectId);
		$map->setGithubOwner($githubOwner);
		$map->setGithubNumber($githubNumber);
		$map->setGithubRepository($githubRepository);
		$map->setDirection($direction);
		$map->setFieldConfig((string)json_encode($fieldConfig));
		$map->setStatusFieldId($fields['statusFieldId']);
		$map->setDateFieldId((string)($fields['dateFieldId'] ?? ''));
		$map->setStartFieldId((string)($fields['startDateFieldId'] ?? ''));
		$map->setLastSync(0);
		$this->maps->insert($map);
		return new DataResponse($this->serialize($map), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function updateMapping(int $id, ?string $direction = null, ?array $fieldConfig = null, ?string $dateFieldId = null, ?string $githubRepository = null): DataResponse {
		try {
			$map = $this->maps->findById($id);
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($map->getUserId() !== $this->userId) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($direction !== null) {
			if (!in_array($direction, ['both', 'deck_to_github', 'github_to_deck'], true)) {
				return new DataResponse(['error' => 'Invalid direction'], Http::STATUS_BAD_REQUEST);
			}
			$map->setDirection($direction);
		}
		if ($fieldConfig !== null) {
			$map->setFieldConfig((string)json_encode($fieldConfig));
		}
		if ($dateFieldId !== null) {
			if ($dateFieldId !== '') {
				try {
					$fields = $this->projects->getFields($this->userId ?? '', $map->getGithubProjectId());
					$valid = array_filter($fields['fields'] ?? [], static fn (array $f): bool => ($f['dataType'] ?? '') === 'DATE' && ($f['id'] ?? '') === $dateFieldId);
					if ($valid === []) {
						return new DataResponse(['error' => 'Ungültiges Datumsfeld.'], Http::STATUS_BAD_REQUEST);
					}
				} catch (\Throwable) {
					return new DataResponse(['error' => 'Datumsfeld konnte nicht geprüft werden.'], Http::STATUS_BAD_GATEWAY);
				}
			}
			$map->setDateFieldId($dateFieldId);
		}
		if ($githubRepository !== null) {
			if ($githubRepository !== '') {
				try {
					$this->projects->getRepositoryNodeId($this->userId ?? '', $githubRepository);
				} catch (\Throwable) {
					return new DataResponse(['error' => 'Das Issue-Repository ist nicht erreichbar oder kann keine Issues erstellen.'], Http::STATUS_BAD_REQUEST);
				}
			}
			$map->setGithubRepository($githubRepository);
		}
		$this->maps->update($map);
		return new DataResponse($this->serialize($map));
	}

	#[NoAdminRequired]
	public function deleteMapping(int $id): DataResponse {
		try {
			$map = $this->maps->findById($id);
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($map->getUserId() !== $this->userId) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$this->db->beginTransaction();
		try {
			foreach ($this->itemMaps->findByMap($id) as $item) {
				$this->itemMaps->delete($item);
			}
			foreach ($this->userMaps->findByMap($id) as $user) {
				$this->userMaps->delete($user);
			}
			$this->maps->delete($map);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			return new DataResponse(['error' => 'Mapping konnte nicht gelöscht werden.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new DataResponse([]);
	}

	public function getAdmin(): DataResponse {
		return new DataResponse([
			'github_app_id' => $this->config->getAppValue('deckgithubsync', 'github_app_id', ''),
			'github_installation_id' => $this->config->getAppValue('deckgithubsync', 'github_installation_id', ''),
			'has_private_key' => $this->config->getAppValue('deckgithubsync', 'github_private_key', '') !== '',
			'has_webhook_secret' => $this->config->getAppValue('deckgithubsync', 'webhook_secret', '') !== '',
			'sync_interval' => (int)$this->config->getAppValue('deckgithubsync', 'sync_interval', '900'),
			'oauth_client_id' => $this->config->getAppValue('deckgithubsync', 'oauth_client_id', ''),
			'has_oauth_secret' => $this->config->getAppValue('deckgithubsync', 'oauth_client_secret', '') !== '',
			'oauth_callback_url' => $this->urls->linkToRouteAbsolute('deckgithubsync.oAuth.callback'),
		]);
	}

	public function setAdmin(string $githubAppId = '', string $githubInstallationId = '', string $githubPrivateKey = '', string $webhookSecret = '', int $syncInterval = 900, string $oauthClientId = '', string $oauthClientSecret = ''): DataResponse {
		$this->config->setAppValue('deckgithubsync', 'github_app_id', $githubAppId);
		$this->config->setAppValue('deckgithubsync', 'github_installation_id', $githubInstallationId);
		if ($githubPrivateKey !== '') {
			$this->config->setAppValue('deckgithubsync', 'github_private_key', $githubPrivateKey);
		}
		if ($webhookSecret !== '') {
			$this->config->setAppValue('deckgithubsync', 'webhook_secret', $webhookSecret);
		}
		$this->config->setAppValue('deckgithubsync', 'sync_interval', (string)max(300, $syncInterval));
		$this->config->setAppValue('deckgithubsync', 'oauth_client_id', $oauthClientId);
		if ($oauthClientSecret !== '') {
			$this->config->setAppValue('deckgithubsync', 'oauth_client_secret', $oauthClientSecret);
		}
		return $this->getAdmin();
	}

	#[NoAdminRequired]
	public function listBoards(): DataResponse {
		try {
			return new DataResponse($this->deck->getBoards($this->userId ?? ''));
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function listProjects(): DataResponse {
		try {
			return new DataResponse($this->projects->listAvailableProjects($this->userId ?? ''));
		} catch (\Throwable $e) {
			$message = str_contains($e->getMessage(), 'INSUFFICIENT_SCOPES')
				? 'GitHub-Token benötigt Project-Leserechte. Bei OAuth bitte erneut verbinden.'
				: 'GitHub Projects konnten nicht geladen werden. Verbindung und Berechtigungen prüfen.';
			return new DataResponse(['error' => $message], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function listRepositories(): DataResponse {
		try {
			return new DataResponse($this->projects->listAvailableRepositories($this->userId ?? ''));
		} catch (\Throwable) {
			return new DataResponse(['error' => 'GitHub-Repositories konnten nicht geladen werden.'], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function githubStatus(): DataResponse {
		$oauth = $this->config->getAppValue('deckgithubsync', 'oauth_client_id', '') !== ''
			&& $this->config->getAppValue('deckgithubsync', 'oauth_client_secret', '') !== '';
		$token = $this->github->getUserToken($this->userId ?? '');
		if ($token === '') {
			return new DataResponse(['connected' => false, 'oauth' => $oauth]);
		}
		try {
			$token = $this->github->getUserAccessToken($this->userId ?? '');
		} catch (\Throwable) {
			return new DataResponse(['connected' => false, 'invalid' => true, 'oauth' => $oauth, 'reason' => 'GitHub-Verbindung abgelaufen. Bitte erneut verbinden.']);
		}
		$result = $this->github->inspectUserToken($token);
		if ($result['login'] === null) {
			return new DataResponse(['connected' => false, 'invalid' => true, 'oauth' => $oauth, 'reason' => $result['error']]);
		}
		return new DataResponse(['connected' => true, 'login' => $result['login'], 'oauth' => $oauth]);
	}

	#[NoAdminRequired]
	public function setUserToken(string $token): DataResponse {
		$token = trim($token);
		if ($token === '') {
			return new DataResponse(['error' => 'Bitte einen GitHub-Token eingeben.'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->github->inspectUserToken($token);
		if ($result['login'] === null) {
			$code = $result['status'] === 401 ? Http::STATUS_BAD_REQUEST : Http::STATUS_BAD_GATEWAY;
			return new DataResponse(['error' => $result['error']], $code);
		}
		$this->github->setUserToken($this->userId ?? '', $token);
		$this->github->setStoredLogin($this->userId ?? '', $result['login']);
		return new DataResponse(['connected' => true, 'login' => $result['login']]);
	}

	#[NoAdminRequired]
	public function disconnectGithub(): DataResponse {
		$this->github->clearUserToken($this->userId ?? '');
		return new DataResponse(['connected' => false]);
	}

	private function serialize(BoardMap $m): array {
		$users = [];
		try {
			foreach ($this->userMaps->findByMap($m->getId()) as $um) {
				$users[] = ['githubLogin' => $um->getGithubLogin(), 'deckUid' => $um->getDeckUid()];
			}
		} catch (\Throwable) {
		}
		return [
			'id' => $m->getId(),
			'deckBoardId' => $m->getDeckBoardId(),
			'githubOwner' => $m->getGithubOwner(),
			'githubNumber' => $m->getGithubNumber(),
			'githubRepository' => $m->getGithubRepository(),
			'direction' => $m->getDirection(),
			'fieldConfig' => $m->getFieldMap(),
			'dateFieldId' => $m->getDateFieldId(),
			'userMap' => $users,
			'lastSync' => $m->getLastSync(),
		];
	}

	#[NoAdminRequired]
	public function setUserMap(int $id, array $users): DataResponse {
		try {
			$map = $this->maps->findById($id);
		} catch (\Exception) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($map->getUserId() !== $this->userId) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		try {
			foreach ($this->userMaps->findByMap($id) as $old) {
				$this->userMaps->delete($old);
			}
			foreach ($users as $u) {
				if (empty($u['githubLogin']) || empty($u['deckUid'])) {
					continue;
				}
				$um = new UserMap();
				$um->setMapId($id);
				$um->setGithubLogin((string)$u['githubLogin']);
				$um->setDeckUid((string)$u['deckUid']);
				$this->userMaps->insert($um);
			}
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->serialize($map));
	}
}
