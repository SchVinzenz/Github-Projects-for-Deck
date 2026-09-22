<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Controller;

use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Public webhook endpoint for GitHub (HMAC verified).
 * Marks affected maps as due; SyncJob executes the sync.
 */
class WebhookController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private BoardMapMapper $maps,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/** @PublicPage @NoCSRFRequired */
	public function github(): DataResponse {
		$secret = $this->config->getAppValue('deckgithubsync', 'webhook_secret', '');
		$body = file_get_contents('php://input') ?: '';
		if ($secret !== '') {
			$sig = $this->request->getHeader('X-Hub-Signature-256');
			$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
			if (!hash_equals($expected, $sig)) {
				return new DataResponse(['error' => 'Invalid signature'], Http::STATUS_UNAUTHORIZED);
			}
		}
		$event = $this->request->getHeader('X-GitHub-Event');
		$payload = json_decode($body, true) ?? [];
		if (($payload['sender']['type'] ?? '') === 'Bot') {
			return new DataResponse(['skipped' => 'bot']);
		}
		$projectNodeId = $payload['projects_v2_item']['project_node_id'] ?? $payload['projectV2']['node_id'] ?? null;
		$queued = 0;
		if (is_string($projectNodeId) && $projectNodeId !== '') {
			foreach ($this->maps->findByProject($projectNodeId) as $map) {
				$map->setLastSync(0);
				$this->maps->update($map);
				$queued++;
			}
		} else {
			// Fallback: unknown scope, mark all due within interval
			$this->logger->info('deckgithubsync webhook without project scope', ['event' => $event, 'action' => $payload['action'] ?? '']);
		}
		$this->logger->info('deckgithubsync webhook', ['event' => $event, 'action' => $payload['action'] ?? '', 'queued' => $queued]);
		return new DataResponse(['queued' => $queued]);
	}
}
