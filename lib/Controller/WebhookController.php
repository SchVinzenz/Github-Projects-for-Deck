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
use OCA\DeckGithubSync\Service\DeckService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Public webhook endpoint for GitHub (HMAC verified).
 *
 * Destructive actions (deleted/archived) are only executed on explicit
 * webhook events, never on listing absence: absence can mean a partial or
 * failed listing. Everything else only marks affected maps as due; the
 * SyncJob executes the sync.
 */
class WebhookController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private BoardMapMapper $maps,
		private ItemMapMapper $items,
		private DeckService $deck,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function github(): DataResponse {
		$secret = $this->config->getAppValue('deckgithubsync', 'webhook_secret', '');
		if ($secret === '') {
			return new DataResponse(['error' => 'Webhook is not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$body = file_get_contents('php://input') ?: '';
		$sig = $this->request->getHeader('X-Hub-Signature-256');
		$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
		if (!hash_equals($expected, $sig)) {
			return new DataResponse(['error' => 'Invalid signature'], Http::STATUS_UNAUTHORIZED);
		}
		$event = $this->request->getHeader('X-GitHub-Event');
		$payload = json_decode($body, true) ?? [];
		if (($payload['sender']['type'] ?? '') === 'Bot') {
			return new DataResponse(['skipped' => 'bot']);
		}
		$action = $payload['action'] ?? '';
		$item = $payload['projects_v2_item'] ?? [];
		$projectNodeId = $item['project_node_id'] ?? $payload['projectV2']['node_id'] ?? null;
		if (!is_string($projectNodeId) || $projectNodeId === '') {
			$this->logger->info('deckgithubsync webhook without project scope', ['event' => $event, 'action' => $action]);
			return new DataResponse(['queued' => 0]);
		}
		$handled = 0;
		$queued = 0;
		foreach ($this->maps->findByProject($projectNodeId) as $map) {
			if ($event === 'projects_v2_item' && $map->getDirection() !== BoardMap::DIR_TO_GITHUB
				&& in_array($action, ['deleted', 'archived', 'restored'], true)) {
				$handled += $this->applyItemEvent($map->getUserId(), (int)$map->getId(), (string)($item['node_id'] ?? ''), $action);
			}
			$map->setLastSync(0);
			$this->maps->update($map);
			$queued++;
		}
		$this->logger->info('deckgithubsync webhook', ['event' => $event, 'action' => $action, 'handled' => $handled, 'queued' => $queued]);
		return new DataResponse(['handled' => $handled, 'queued' => $queued]);
	}

	private function applyItemEvent(string $userId, int $mapId, string $itemNodeId, string $action): int {
		if ($itemNodeId === '') {
			return 0;
		}
		try {
			$link = $this->items->findByGithubItem($mapId, $itemNodeId);
		} catch (\Throwable) {
			return 0;
		}
		if ($link === null) {
			return 0;
		}
		try {
			if ($action === 'deleted') {
				$this->deck->deleteCard($userId, $link->getDeckCardId());
				$this->items->delete($link);
			} elseif ($action === 'archived') {
				$this->deck->archiveCard($userId, $link->getDeckCardId(), true);
			} elseif ($action === 'restored') {
				$this->deck->archiveCard($userId, $link->getDeckCardId(), false);
			}
			return 1;
		} catch (\Throwable $e) {
			$this->logger->warning('deckgithubsync: webhook item event failed', ['exception' => $e]);
			return 0;
		}
	}
}
