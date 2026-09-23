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
		foreach ($boardService->findAll(-1, false, false) as $b) {
			$out[] = ['id' => $b->getId(), 'title' => $b->getTitle()];
		}
		return $out;
	}

	/** @return array<int, array{id:int,title:string}> */
	public function getStacks(string $userId, int $boardId): array {
		$boardService = $this->boardService($userId);
		$boardService->find($boardId);
		$stackService = Server::get('OCA\Deck\Db\StackMapper');
		$out = [];
		foreach ($stackService->findAll($boardId) as $s) {
			$out[] = ['id' => $s->getId(), 'title' => $s->getTitle()];
		}
		return $out;
	}

	public function createStack(string $userId, int $boardId, string $title, int $order): int {
		$this->boardService($userId)->find($boardId);
		$stackService = Server::get('OCA\Deck\Service\StackService');
		return $stackService->create($title, $boardId, $order)->getId();
	}

	/** @return array<int, array> cards with stackId, title, description, duedate, labels, assignedUsers */
	public function getCards(string $userId, int $boardId): array {
		$boardService = $this->boardService($userId);
		$board = $boardService->find($boardId);
		$stackService = Server::get('OCA\Deck\Db\StackMapper');
		$cardMapper = Server::get('OCA\Deck\Db\CardMapper');
		$labelMapper = Server::get('OCA\Deck\Db\LabelMapper');
		$assignmentMapper = Server::get('OCA\Deck\Db\AssignmentMapper');
		$out = [];
		$stacks = $stackService->findAll($board->getId());
		$stackById = [];
		foreach ($stacks as $stack) {
			$stackById[$stack->getId()] = $stack->getTitle();
		}
		if ($stackById === []) {
			return [];
		}
		foreach (array_chunk(array_keys($stackById), 100) as $stackIds) {
			$byStack = $cardMapper->findAllForStacks($stackIds);
			$cards = array_merge(...array_values(array_filter($byStack)));
			if ($cards === []) {
				continue;
			}
			$cardIds = array_map(static fn ($c): int => $c->getId(), $cards);
			$labels = [];
			$assignees = [];
			foreach (array_chunk($cardIds, 500) as $ids) {
				foreach ($labelMapper->findAssignedLabelsForCards($ids) as $label) {
					$labels[$label->getCardId()][] = $label->getTitle();
				}
				foreach ($assignmentMapper->findIn($ids) as $assignment) {
					$assignees[$assignment->getCardId()][] = $assignment->getParticipant();
				}
			}
			foreach ($cards as $details) {
				$out[] = [
					'id' => $details->getId(),
					'stackId' => $details->getStackId(),
					'stackTitle' => $stackById[$details->getStackId()],
					'title' => $details->getTitle(),
					'description' => $details->getDescription() ?? '',
					'duedate' => $this->dateOrNull($details->getDuedate()),
					'startdate' => $this->dateOrNull($details->getStartdate()),
					'done' => $details->getDone() !== null,
					'labels' => $labels[$details->getId()] ?? [],
					'assignedUsers' => $assignees[$details->getId()] ?? [],
					'lastModified' => $details->getLastModified(),
				];
			}
		}
		return $out;
	}

	public function createCard(string $userId, int $stackId, string $title, string $description = '', ?string $duedate = null, ?string $startdate = null): int {
		$this->boardService($userId);
		$cardService = Server::get('OCA\Deck\Service\CardService');
		$card = $cardService->create($title, $stackId, 'plain', 999, $userId, $description, $duedate, $startdate);
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
			array_key_exists('startdate', $patch) ? $patch['startdate'] : $this->dateOrNull($current->getStartdate()),
		);
	}

	public function setDone(string $userId, int $cardId, bool $done): void {
		$this->boardService($userId);
		$service = Server::get('OCA\Deck\Service\CardService');
		$current = $service->find($cardId)->getDone() !== null;
		if ($current !== $done) {
			$done ? $service->done($cardId) : $service->undone($cardId);
		}
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
			throw $e;
		}
	}

	/** Ensure board labels exist and make the card's labels match GitHub. @param string[] $titles */
	public function syncLabels(string $userId, int $boardId, int $cardId, array $titles, bool $replace = true): void {
		try {
			$this->boardService($userId);
			$labelMapper = Server::get('OCA\Deck\Db\LabelMapper');
			$labelService = Server::get('OCA\Deck\Service\LabelService');
			$cardService = Server::get('OCA\Deck\Service\CardService');
			$wanted = array_fill_keys(array_map('mb_strtolower', $titles), true);
			if ($replace) {
				foreach ($cardService->find($cardId)->getLabels() ?? [] as $current) {
					if (!isset($wanted[mb_strtolower($current->getTitle())])) {
						$cardService->removeLabel($cardId, $current->getId());
					}
				}
			}
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
			throw $e;
		}
	}

	/** Synchronize mapped Deck users; unknown users are skipped. @param string[] $deckUserIds @param string[] $managedUserIds */
	public function syncAssignees(string $userId, int $cardId, array $deckUserIds, array $managedUserIds = []): void {
		try {
			$this->boardService($userId);
			$assignmentService = Server::get('OCA\Deck\Service\AssignmentService');
			$cardService = Server::get('OCA\Deck\Service\CardService');
			$wanted = array_fill_keys($deckUserIds, true);
			$managed = array_fill_keys($managedUserIds, true);
			$current = [];
			foreach ($cardService->find($cardId)->getAssignedUsers() ?? [] as $assignment) {
				$uid = is_array($assignment) ? ($assignment['participant'] ?? '') : $assignment->getParticipant();
				if ($uid === '') {
					continue;
				}
				$current[$uid] = true;
				if (isset($managed[$uid]) && !isset($wanted[$uid])) {
					$assignmentService->unassignUser($cardId, $uid);
				}
			}
			foreach ($deckUserIds as $u) {
				if (isset($current[$u])) {
					continue;
				}
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
