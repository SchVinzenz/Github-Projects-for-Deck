<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Access Deck via internal services when installed, otherwise throw.
 *
 * Targets Deck 2.x (BoardService::find($boardId), StackService::findAll($boardId),
 * CardService::find($cardId), LabelMapper::findAll($boardId)). The Deck user
 * context is set via BoardService::setUserId(), which propagates to the shared
 * PermissionService. Full card payloads are sent on update() because Deck
 * clears omitted fields.
 */
class DeckService {
	public function __construct(
		private LoggerInterface $logger,
		private ICommentsManager $commentsManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	public function assertAvailable(): void {
		if (!class_exists('OCA\Deck\Service\BoardService')) {
			throw new \RuntimeException('Deck app is not installed/enabled');
		}
	}

	private function boardService(string $userId): mixed {
		$this->assertAvailable();
		$user = $this->userManager->get($userId);
		if ($user !== null && $this->userSession->getUser()?->getUID() !== $userId) {
			$this->userSession->setUser($user);
		}
		$boardService = Server::get('OCA\Deck\Service\BoardService');
		if (method_exists($boardService, 'setUserId')) {
			$boardService->setUserId($userId);
		}
		return $boardService;
	}

	/** @return array<int, array{id:int,title:string}> */
	public function getBoards(string $userId): array {
		$boardService = $this->boardService($userId);
		$out = [];
		foreach ($boardService->findAll() as $b) {
			$out[] = ['id' => $b->getId(), 'title' => $b->getTitle()];
		}
		return $out;
	}

	/** @return array<int, array{id:int,title:string}> */
	public function getStacks(string $userId, int $boardId): array {
		$boardService = $this->boardService($userId);
		$boardService->find($boardId);
		$stackService = Server::get('OCA\Deck\Service\StackService');
		$out = [];
		foreach ($stackService->findAll($boardId) as $s) {
			$out[] = ['id' => $s->getId(), 'title' => $s->getTitle()];
		}
		return $out;
	}

	/** @return array<int, array> cards with stackId, title, description, duedate, labels, assignedUsers */
	public function getCards(string $userId, int $boardId): array {
		$boardService = $this->boardService($userId);
		$board = $boardService->find($boardId);
		$stackService = Server::get('OCA\Deck\Service\StackService');
		$cardService = Server::get('OCA\Deck\Service\CardService');
		$cardMapper = Server::get('OCA\Deck\Db\CardMapper');
		$out = [];
		foreach ($stackService->findAll($board->getId()) as $stack) {
			foreach ($cardMapper->findAll($stack->getId()) as $c) {
				$details = $cardService->find($c->getId());
				$out[] = [
					'id' => $details->getId(),
					'stackId' => $stack->getId(),
					'stackTitle' => $stack->getTitle(),
					'title' => $details->getTitle(),
					'description' => $details->getDescription() ?? '',
					'duedate' => $this->dateOrNull($details->getDuedate()),
					'labels' => $this->labelTitles($details->getLabels() ?? []),
					'assignedUsers' => $this->assigneeUids($details->getAssignedUsers() ?? []),
					'lastModified' => $details->getLastModified(),
				];
			}
		}
		return $out;
	}

	public function createCard(string $userId, int $stackId, string $title, string $description = '', ?string $duedate = null): int {
		$this->boardService($userId);
		$cardService = Server::get('OCA\Deck\Service\CardService');
		$card = $cardService->create($title, $stackId, 'plain', 999, $userId, $description, $duedate);
		return $card->getId();
	}

	/** Full round-trip update to avoid Deck clearing omitted fields. */
	public function updateCard(string $userId, int $cardId, array $patch): void {
		$this->boardService($userId);
		$cardService = Server::get('OCA\Deck\Service\CardService');
		$current = $cardService->find($cardId);
		$cardService->update(
			$cardId,
			$patch['title'] ?? $current->getTitle(),
			$patch['stackId'] ?? $current->getStackId(),
			$patch['type'] ?? $current->getType(),
			$patch['owner'] ?? $current->getOwner(),
			$patch['description'] ?? $current->getDescription(),
			$patch['order'] ?? $current->getOrder(),
			array_key_exists('duedate', $patch) ? $patch['duedate'] : $this->dateOrNull($current->getDuedate()),
			null,
			null,
			$current->getDone() === null ? null : new \OCA\Deck\Model\OptionalNullableValue($current->getDone()),
			$this->dateOrNull($current->getStartdate()),
		);
	}

	public function moveCard(string $userId, int $cardId, int $targetStackId): void {
		$this->boardService($userId);
		$cardService = Server::get('OCA\Deck\Service\CardService');
		$current = $cardService->find($cardId);
		$this->updateCard($userId, $cardId, [
			'stackId' => $targetStackId,
			'title' => $current->getTitle(),
			'description' => $current->getDescription(),
			'duedate' => $this->dateOrNull($current->getDuedate()),
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
			$comment = $this->commentsManager->create('users', $actorId !== '' ? $actorId : $userId, 'deckCard', (string)$cardId);
			$comment->setMessage($message);
			$comment->setVerb('comment');
			$this->commentsManager->save($comment);
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: comment create failed', ['exception' => $e]);
		}
	}

	/** Ensure board labels exist and assign missing ones to card. @param string[] $titles */
	public function syncLabels(string $userId, int $boardId, int $cardId, array $titles): void {
		try {
			$this->boardService($userId);
			$labelMapper = Server::get('OCA\Deck\Db\LabelMapper');
			$labelService = Server::get('OCA\Deck\Service\LabelService');
			$cardService = Server::get('OCA\Deck\Service\CardService');
			$byTitle = [];
			foreach ($labelMapper->findAll($boardId) as $bl) {
				$byTitle[strtolower($bl->getTitle())] = $bl->getId();
			}
			foreach ($titles as $t) {
				$t = trim($t);
				if ($t === '') {
					continue;
				}
				if (!isset($byTitle[strtolower($t)])) {
					$new = $labelService->create($t, '000000', $boardId);
					$byTitle[strtolower($t)] = $new->getId();
				}
				$cardService->assignLabel($cardId, $byTitle[strtolower($t)]);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: label sync failed', ['exception' => $e]);
		}
	}

	/** Assign Deck users that have board access. Unknown users are skipped. @param string[] $deckUserIds */
	public function syncAssignees(string $userId, int $cardId, array $deckUserIds): void {
		try {
			$this->boardService($userId);
			$assignmentService = Server::get('OCA\Deck\Service\AssignmentService');
			foreach ($deckUserIds as $u) {
				try {
					$assignmentService->assignUser($cardId, trim($u));
				} catch (\Throwable $e) {
					$this->logger->debug('deckgithubsync: assignee sync skipped', ['user' => $u]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: assignee sync failed', ['exception' => $e]);
		}
	}

	public function archiveCard(string $userId, int $cardId, bool $archive = true): void {
		$this->boardService($userId);
		$cardService = Server::get('OCA\Deck\Service\CardService');
		if ($archive) {
			$cardService->archive($cardId);
		} else {
			$cardService->unarchive($cardId);
		}
	}

	public function deleteCard(string $userId, int $cardId): void {
		$this->boardService($userId);
		$cardService = Server::get('OCA\Deck\Service\CardService');
		$cardService->delete($cardId);
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

	private function dateOrNull(mixed $duedate): ?string {
		if ($duedate === null) {
			return null;
		}
		if ($duedate instanceof \DateTimeInterface) {
			return $duedate->format('Y-m-d\TH:i:sP');
		}
		return is_string($duedate) ? $duedate : null;
	}

	/** @param array $labels */
	private function labelTitles(array $labels): array {
		$out = [];
		foreach ($labels as $l) {
			$out[] = is_array($l) ? ($l['title'] ?? '') : (method_exists($l, 'getTitle') ? $l->getTitle() : (string)$l);
		}
		return array_values(array_filter($out));
	}

	/** @param array $users */
	private function assigneeUids(array $users): array {
		$out = [];
		foreach ($users as $u) {
			$out[] = is_array($u) ? ($u['participant'] ?? $u['uid'] ?? '') : (method_exists($u, 'getParticipant') ? $u->getParticipant() : (string)$u);
		}
		return array_values(array_filter($out));
	}
}
