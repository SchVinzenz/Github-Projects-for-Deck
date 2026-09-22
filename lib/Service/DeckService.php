<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

use OCP\Comments\ICommentsManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Access Deck via internal services when installed, otherwise throw.
 *
 * Uses OCA\Deck\Service\{BoardService,StackService,CardService} with the
 * sync user's context. Keeps full card payloads for update() round-trips
 * (Deck clears omitted fields).
 */
class DeckService {
	public function __construct(
		private LoggerInterface $logger,
		private ICommentsManager $commentsManager,
	) {
	}

	public function assertAvailable(): void {
		if (!class_exists('OCA\Deck\Service\BoardService')) {
			throw new \RuntimeException('Deck app is not installed/enabled');
		}
	}

	private function services(string $userId): array {
		$this->assertAvailable();
		\OC::$server->getUserSession()->setUser(null);
		$user = \OC::$server->getUserManager()->get($userId);
		if ($user !== null) {
			\OC::$server->getUserSession()->setUser($user);
		}
		return [
			Server::get('OCA\Deck\Service\BoardService'),
			Server::get('OCA\Deck\Service\StackService'),
			Server::get('OCA\Deck\Service\CardService'),
		];
	}

	/** @return array<int, array{id:int,title:string}> */
	public function getStacks(string $userId, int $boardId): array {
		[$boardService, $stackService] = $this->services($userId);
		$boardService->find($boardId, $userId);
		$stacks = $stackService->findAll($boardId, $userId);
		$out = [];
		foreach ($stacks as $s) {
			$out[] = ['id' => $s->getId(), 'title' => $s->getTitle()];
		}
		return $out;
	}

	/** @return array<int, array> cards with stackId, title, description, duedate, labels, assignedUsers, comments */
	public function getCards(string $userId, int $boardId): array {
		[$boardService, $stackService, $cardService] = $this->services($userId);
		$board = $boardService->find($boardId, $userId);
		$stacks = $stackService->findAll($board->getId(), $userId);
		$out = [];
		foreach ($stacks as $stack) {
			$cards = $cardService->findAll($stack->getId(), $userId);
			foreach ($cards as $c) {
				$details = $cardService->find($c->getId(), $userId);
				$out[] = [
					'id' => $details->getId(),
					'stackId' => $stack->getId(),
					'stackTitle' => $stack->getTitle(),
					'title' => $details->getTitle(),
					'description' => $details->getDescription() ?? '',
					'duedate' => $details->getDuedate(),
					'labels' => array_map(fn ($l) => is_array($l) ? ($l['title'] ?? '') : $l->getTitle(), $details->getLabels() ?? []),
					'assignedUsers' => array_map(fn ($u) => is_array($u) ? ($u['participant'] ?? '') : $u->getParticipant(), $details->getAssignedUsers() ?? []),
					'lastModified' => $details->getLastModified(),
				];
			}
		}
		return $out;
	}

	public function createCard(string $userId, int $stackId, string $title, string $description = '', ?string $duedate = null): int {
		[, , $cardService] = $this->services($userId);
		$card = $cardService->create($title, $stackId, 'plain', 999, $userId, $description, $duedate);
		return $card->getId();
	}

	/** Full round-trip update to avoid Deck clearing omitted fields. */
	public function updateCard(string $userId, int $cardId, array $patch): void {
		[, , $cardService] = $this->services($userId);
		$current = $cardService->find($cardId, $userId);
		$cardService->update(
			$cardId,
			$patch['stackId'] ?? $current->getStackId(),
			$patch['title'] ?? $current->getTitle(),
			$patch['type'] ?? $current->getType(),
			$patch['order'] ?? $current->getOrder(),
			$patch['description'] ?? $current->getDescription(),
			$patch['owner'] ?? $current->getOwner(),
			$patch['duedate'] ?? $current->getDuedate()
		);
	}

	public function moveCard(string $userId, int $cardId, int $targetStackId): void {
		[, , $cardService] = $this->services($userId);
		$current = $cardService->find($cardId, $userId);
		$this->updateCard($userId, $cardId, [
			'stackId' => $targetStackId,
			'title' => $current->getTitle(),
			'description' => $current->getDescription(),
			'duedate' => $current->getDuedate(),
			'order' => 999,
		]);
	}

	/** @return string[] comment messages */
	public function getComments(int $cardId): array {
		try {
			$comments = $this->commentsManager->getForObject('deckCard', (string)$cardId);
			return array_map(fn ($c) => $c->getMessage(), $comments);
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: comment fetch failed', ['exception' => $e]);
			return [];
		}
	}

	public function addComment(string $userId, int $cardId, string $message, string $actorId = ''): void {
		try {
			$this->commentsManager->create($actorId !== '' ? $actorId : $userId, 'deckCard', (string)$cardId, $message);
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: comment create failed', ['exception' => $e]);
		}
	}

	/** Ensure board labels exist and assign missing ones to card. @param string[] $titles */
	public function syncLabels(string $userId, int $boardId, int $cardId, array $titles): void {
		[, , $cardService] = $this->services($userId);
		try {
			if (!method_exists($cardService, 'assignLabel')) {
				return;
			}
			$boardLabels = method_exists($cardService, 'getBoardLabels') ? $cardService->getBoardLabels($boardId, $userId) : [];
			$byTitle = [];
			foreach ($boardLabels as $bl) {
				$byTitle[strtolower(is_array($bl) ? ($bl['title'] ?? '') : $bl->getTitle())] = is_array($bl) ? ($bl['id'] ?? 0) : $bl->getId();
			}
			foreach ($titles as $t) {
				$t = trim($t);
				if ($t === '') {
					continue;
				}
				if (!isset($byTitle[strtolower($t)])) {
					if (!method_exists($cardService, 'createLabel')) {
						continue;
					}
					$new = $cardService->createLabel($boardId, $t, '000000', $userId);
					$byTitle[strtolower($t)] = is_array($new) ? ($new['id'] ?? 0) : $new->getId();
				}
				$cardService->assignLabel($cardId, $byTitle[strtolower($t)], $userId);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: label sync failed', ['exception' => $e]);
		}
	}

	/** Assign Deck users that have board access. Unknown users are skipped. @param string[] $deckUserIds */
	public function syncAssignees(string $userId, int $cardId, array $deckUserIds): void {
		[, , $cardService] = $this->services($userId);
		if (!method_exists($cardService, 'assignUser')) {
			return;
		}
		foreach ($deckUserIds as $u) {
			try {
				$cardService->assignUser($cardId, trim($u), $userId);
			} catch (\Throwable $e) {
				$this->logger->debug('deckgithubsync: assignee sync skipped', ['user' => $u]);
			}
		}
	}

	public function archiveCard(string $userId, int $cardId, bool $archive = true): void {
		[, , $cardService] = $this->services($userId);
		try {
			if ($archive && method_exists($cardService, 'archive')) {
				$cardService->archive($cardId, $userId);
			} elseif (!$archive && method_exists($cardService, 'unarchive')) {
				$cardService->unarchive($cardId, $userId);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: archive failed', ['exception' => $e]);
		}
	}

	public function deleteCard(string $userId, int $cardId): void {
		[, , $cardService] = $this->services($userId);
		try {
			if (method_exists($cardService, 'delete')) {
				$cardService->delete($cardId, $userId);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: delete failed', ['exception' => $e]);
		}
	}

	/** Normalize Deck duedate (timestamp|string|null) to Y-m-d or null. */
	public static function normalizeDue(mixed $duedate): ?string {
		if ($duedate === null || $duedate === '' || $duedate === 0 || $duedate === '0') {
			return null;
		}
		if (is_numeric($duedate)) {
			return gmdate('Y-m-d', (int)$duedate);
		}
		$ts = strtotime((string)$duedate);
		return $ts === false ? null : gmdate('Y-m-d', $ts);
	}
}
