<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Db\ItemMap;
use OCA\DeckGithubSync\Db\ItemMapMapper;
use OCA\DeckGithubSync\Db\UserMapMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Bidirectional sync with per-board direction + per-field directions.
 * Loop protection: sync hash + last-write-wins via timestamps.
 * PR items are read-only (GitHub -> Deck only).
 */
class SyncService {
	public function __construct(
		private BoardMapMapper $boardMaps,
		private ItemMapMapper $itemMaps,
		private UserMapMapper $userMaps,
		private DeckService $deck,
		private GithubProjectService $github,
		private LoggerInterface $logger,
	) {
	}

	public function syncBoard(BoardMap $map): array {
		$userId = $map->getUserId();
		$stats = ['deck_to_github' => 0, 'github_to_deck' => 0, 'errors' => []];
		try {
			$this->deck->assertAvailable();
		} catch (\Throwable $e) {
			$stats['errors'][] = $e->getMessage();
			return $stats;
		}

		$fieldMap = $map->getFieldMap();
		$boardDir = $map->getDirection();
		$allowToGithub = in_array($boardDir, [BoardMap::DIR_BOTH, BoardMap::DIR_TO_GITHUB], true);
		$allowToDeck = in_array($boardDir, [BoardMap::DIR_BOTH, BoardMap::DIR_TO_DECK], true);

		$deckCards = $this->deck->getCards($userId, $map->getDeckBoardId());
		$stacks = $this->deck->getStacks($userId, $map->getDeckBoardId());
		$stackByTitle = [];
		$stackById = [];
		foreach ($stacks as $s) {
			$stackByTitle[strtolower($s['title'])] = $s['id'];
			$stackById[$s['id']] = $s['title'];
		}

		$fields = $this->github->getFields($userId, $map->getGithubProjectId());
		$statusFieldId = $map->getStatusFieldId() !== '' ? $map->getStatusFieldId() : $fields['statusFieldId'];
		$dateFieldId = $map->getDateFieldId() !== '' ? $map->getDateFieldId() : ($fields['dateFieldId'] ?? '');
		if ($map->getStatusFieldId() === '' && $statusFieldId !== '') {
			$map->setStatusFieldId($statusFieldId);
		}
		if ($map->getDateFieldId() === '' && $dateFieldId !== '') {
			$map->setDateFieldId($dateFieldId);
		}
		$options = $fields['options']; // name => id

		$userMap = $this->loadUserMap($map->getId()); // githubLogin(lower) => deckUid + reverse

		$githubItems = [];
		$after = null;
		do {
			$page = $this->github->listItems($userId, $map->getGithubProjectId(), $after);
			foreach ($page['items'] as $it) {
				$githubItems[$it['id']] = $it;
			}
			$after = $page['hasNext'] ? $page['cursor'] : null;
		} while ($after !== null);

		$known = [];
		foreach ($this->itemMaps->findByMap($map->getId()) as $im) {
			$known['deck:' . $im->getDeckCardId()] = $im;
			$known['gh:' . $im->getGithubItemId()] = $im;
		}

		// Deck -> GitHub
		if ($allowToGithub) {
			foreach ($deckCards as $card) {
				try {
					$key = 'deck:' . $card['id'];
					$existing = $known[$key] ?? null;
					$hash = $this->hashDeck($card);
					if ($existing !== null && $existing->getSyncHash() === $hash && isset($githubItems[$existing->getGithubItemId()])) {
						continue;
					}
					if ($existing !== null && isset($githubItems[$existing->getGithubItemId()])) {
						$gItem = $githubItems[$existing->getGithubItemId()];
						if (($gItem['content']['__typename'] ?? '') === 'PullRequest') {
							continue; // PRs are read-only, never push
						}
						if ($allowToDeck && $allowToGithub && $this->newerSide($card, $gItem) === 'github') {
							$this->pullGithubToDeck($map, $userId, $card, $gItem, $fieldMap, $stackByTitle, $dateFieldId, $userMap);
							$existing->setSyncHash($this->hashDeck($this->findDeckCard($card['id'], $deckCards) ?? $card));
							$this->itemMaps->update($existing);
							$stats['github_to_deck']++;
							continue;
						}
						$this->pushDeckToGithub($map, $userId, $card, $gItem, $fieldMap, $statusFieldId, $options, $stackById, $dateFieldId, $userMap);
						$existing->setSyncHash($hash);
						$this->itemMaps->update($existing);
					} else {
						$itemId = $this->github->addDraft($userId, $map->getGithubProjectId(), $card['title'], $card['description']);
						if ($itemId === null) {
							continue;
						}
						$stackTitle = $stackById[$card['stackId']] ?? '';
						if ($this->fieldAllowed($fieldMap, 'status', BoardMap::DIR_TO_GITHUB) && $stackTitle !== '' && isset($options[$stackTitle]) && $statusFieldId !== '') {
							$this->github->setStatus($userId, $map->getGithubProjectId(), $itemId, $statusFieldId, $options[$stackTitle]);
						}
						if ($this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_GITHUB) && $dateFieldId !== '') {
							$deckDue = DeckService::normalizeDue($card['duedate'] ?? null);
							if ($deckDue !== null) {
								$this->github->setDate($userId, $map->getGithubProjectId(), $itemId, $dateFieldId, $deckDue);
							}
						}
						$im = new ItemMap();
						$im->setMapId($map->getId());
						$im->setDeckCardId($card['id']);
						$im->setGithubItemId($itemId);
						$im->setGithubContentId('');
						$im->setContentType('DraftIssue');
						$im->setSyncHash($hash);
						$this->itemMaps->insert($im);
					}
					$stats['deck_to_github']++;
				} catch (\Throwable $e) {
					$stats['errors'][] = 'deck card ' . $card['id'] . ': ' . $e->getMessage();
				}
			}
		}

		// GitHub -> Deck (new + updates for known items)
		if ($allowToDeck) {
			foreach ($githubItems as $itemId => $item) {
				try {
					$link = $known['gh:' . $itemId] ?? null;
					if ($link !== null) {
						$card = $this->findDeckCard($link->getDeckCardId(), $deckCards);
						if ($card === null) {
							continue;
						}
						if ($this->hashGithub($item) === $link->getSyncHash()) {
							continue;
						}
						if ($allowToGithub && $this->newerSide($card, $item) === 'deck') {
							continue; // deck wins, handled in Deck->GitHub pass
						}
						$this->pullGithubToDeck($map, $userId, $card, $item, $fieldMap, $stackByTitle, $dateFieldId, $userMap);
						$link->setSyncHash($this->hashGithub($item));
						$this->itemMaps->update($link);
						$stats['github_to_deck']++;
						continue;
					}
					$content = $item['content'] ?? [];
					$title = $content['title'] ?? ('GitHub item ' . substr($itemId, 0, 8));
					$body = $content['body'] ?? '';
					if (($content['__typename'] ?? '') === 'PullRequest') {
						$body .= "\n\n[GitHub PR, read-only: " . ($content['url'] ?? '') . ']';
					}
					$status = GithubProjectService::statusOf($item);
					$targetStack = $stackByTitle[strtolower($status)] ?? reset($stacks)['id'] ?? null;
					if ($targetStack === null) {
						continue;
					}
					$due = $this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_DECK) ? GithubProjectService::dateOf($item, $dateFieldId) : null;
					$cardId = $this->deck->createCard($userId, (int)$targetStack, $title, $body, $due);
					if ($this->fieldAllowed($fieldMap, 'labels', BoardMap::DIR_TO_DECK)) {
						$ghLabels = GithubProjectService::labelsOf($item);
						if ($ghLabels !== []) {
							$this->deck->syncLabels($userId, $map->getDeckBoardId(), $cardId, $ghLabels);
						}
					}
					$im = new ItemMap();
					$im->setMapId($map->getId());
					$im->setDeckCardId($cardId);
					$im->setGithubItemId($itemId);
					$im->setGithubContentId($content['id'] ?? '');
					$im->setContentType($content['__typename'] ?? 'DraftIssue');
					$im->setSyncHash($this->hashGithub($item));
					$this->itemMaps->insert($im);
					$stats['github_to_deck']++;
				} catch (\Throwable $e) {
					$stats['errors'][] = 'github item ' . $itemId . ': ' . $e->getMessage();
				}
			}
		}

		$prop = $this->propagateDeletions($map, $userId, $deckCards, $githubItems, $known, $allowToGithub, $allowToDeck, $fieldMap);
		$stats = array_merge($stats, $prop);

		$map->setLastSync(time());
		$this->boardMaps->update($map);
		return $stats;
	}

	private function pushDeckToGithub(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, string $statusFieldId, array $options, array $stackById = [], string $dateFieldId = '', array $userMap = []): void {
		$content = $gItem['content'] ?? [];
		$type = $content['__typename'] ?? 'DraftIssue';
		if ($type === 'PullRequest') {
			return; // read-only
		}
		if ($type === 'DraftIssue') {
			if ($this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_GITHUB) || $this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_GITHUB)) {
				$this->github->updateDraft($userId, $gItem['id'], $content['id'] ?? '', $card['title'], $card['description']);
			}
		} elseif ($type === 'Issue') {
			$patch = [];
			if ($this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_GITHUB) && ($content['title'] ?? '') !== $card['title']) {
				$patch['title'] = $card['title'];
			}
			if ($this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_GITHUB) && ($content['body'] ?? '') !== ($card['description'] ?? '')) {
				$patch['body'] = $card['description'] ?? '';
			}
			$repo = GithubProjectService::repoOf($gItem);
			$number = GithubProjectService::numberOf($gItem);
			if ($patch !== [] && $repo !== null && $number !== null) {
				$this->github->updateIssueRest($userId, $repo, $number, $patch);
			}
			if ($repo !== null && $number !== null) {
				if ($this->fieldAllowed($fieldMap, 'labels', BoardMap::DIR_TO_GITHUB)) {
					$ghLabels = GithubProjectService::labelsOf($gItem);
					if (array_diff($card['labels'] ?? [], $ghLabels) !== [] || array_diff($ghLabels, $card['labels'] ?? []) !== []) {
						$this->github->setIssueLabels($userId, $repo, $number, $card['labels'] ?? []);
					}
				}
				if ($this->fieldAllowed($fieldMap, 'assignees', BoardMap::DIR_TO_GITHUB) && $userMap !== []) {
					$ghLogins = array_map('strtolower', GithubProjectService::assigneesOf($gItem));
					$wantLogins = [];
					foreach ($card['assignedUsers'] ?? [] as $uid) {
						$login = $userMap['deck2gh'][strtolower((string)$uid)] ?? null;
						if ($login !== null) {
							$wantLogins[] = strtolower($login);
						}
					}
					if (array_diff($wantLogins, $ghLogins) !== [] || array_diff($ghLogins, $wantLogins) !== []) {
						$this->github->setIssueAssignees($userId, $repo, $number, array_values(array_unique($wantLogins)));
					}
				}
				if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_GITHUB)) {
					$this->pushMissingComments($userId, $card, $repo, $number);
				}
			}
		}
		if ($this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_GITHUB) && $dateFieldId !== '') {
			$deckDue = DeckService::normalizeDue($card['duedate'] ?? null);
			$ghDue = GithubProjectService::dateOf($gItem, $dateFieldId);
			if ($deckDue !== $ghDue) {
				$this->github->setDate($userId, $map->getGithubProjectId(), $gItem['id'], $dateFieldId, $deckDue);
			}
		}
		$stackTitle = $stackById[$card['stackId']] ?? '';
		$currentStatus = GithubProjectService::statusOf($gItem);
		if ($this->fieldAllowed($fieldMap, 'status', BoardMap::DIR_TO_GITHUB)
			&& $stackTitle !== '' && $stackTitle !== $currentStatus
			&& isset($options[$stackTitle]) && $statusFieldId !== '') {
			$this->github->setStatus($userId, $map->getGithubProjectId(), $gItem['id'], $statusFieldId, $options[$stackTitle]);
		}
	}

	private function pushMissingComments(string $userId, array $card, string $repo, int $number): void {
		$deckComments = $this->deck->getComments((int)$card['id']);
		if ($deckComments === []) {
			return;
		}
		$ghComments = $this->github->getIssueComments($userId, $repo, $number);
		$ghBodies = array_map(fn ($c) => trim($c['body']), $ghComments);
		foreach ($deckComments as $msg) {
			if (!in_array(trim($msg), $ghBodies, true)) {
				$this->github->addIssueComment($userId, $repo, $number, '[Deck] ' . $msg);
			}
		}
	}

	/** Last-write-wins: newer side wins per item, decided by updatedAt/lastModified. */
	public function newerSide(array $card, array $gItem): string {
		$deckTs = (int)($card['lastModified'] ?? 0);
		$ghTs = strtotime($gItem['updatedAt'] ?? '@0') ?: 0;
		$contentTs = strtotime($gItem['content']['updatedAt'] ?? '@0') ?: 0;
		$ghTs = max($ghTs, $contentTs);
		return $deckTs >= $ghTs ? 'deck' : 'github';
	}

	private function fieldAllowed(array $fieldMap, string $field, string $wanted): bool {
		$dir = $fieldMap[$field] ?? BoardMap::DIR_BOTH;
		if ($dir === 'off') {
			return false;
		}
		return $dir === BoardMap::DIR_BOTH || $dir === $wanted;
	}

	/** @param array<int,array> $deckCards */
	private function findDeckCard(int $cardId, array $deckCards): ?array {
		foreach ($deckCards as $c) {
			if ((int)($c['id'] ?? 0) === $cardId) {
				return $c;
			}
		}
		return null;
	}

	private function pullGithubToDeck(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, array $stackByTitle, string $dateFieldId = '', array $userMap = []): void {
		$content = $gItem['content'] ?? [];
		$patch = [];
		if ($this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_DECK) && isset($content['title']) && $content['title'] !== $card['title']) {
			$patch['title'] = $content['title'];
		}
		if ($this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_DECK) && isset($content['body']) && $content['body'] !== ($card['description'] ?? '')) {
			$patch['description'] = $content['body'];
		}
		if ($this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_DECK) && $dateFieldId !== '') {
			$ghDue = GithubProjectService::dateOf($gItem, $dateFieldId);
			$deckDue = DeckService::normalizeDue($card['duedate'] ?? null);
			if ($ghDue !== $deckDue) {
				$patch['duedate'] = $ghDue;
			}
		}
		if ($this->fieldAllowed($fieldMap, 'status', BoardMap::DIR_TO_DECK)) {
			$status = GithubProjectService::statusOf($gItem);
			if ($status !== '' && isset($stackByTitle[strtolower($status)]) && (int)$stackByTitle[strtolower($status)] !== (int)$card['stackId']) {
				$patch['stackId'] = (int)$stackByTitle[strtolower($status)];
			}
		}
		if ($patch !== []) {
			$this->deck->updateCard($userId, (int)$card['id'], $patch);
		}
		if ($this->fieldAllowed($fieldMap, 'labels', BoardMap::DIR_TO_DECK)) {
			$ghLabels = GithubProjectService::labelsOf($gItem);
			if ($ghLabels !== []) {
				$this->deck->syncLabels($userId, $map->getDeckBoardId(), (int)$card['id'], $ghLabels);
			}
		}
		if ($this->fieldAllowed($fieldMap, 'assignees', BoardMap::DIR_TO_DECK) && $userMap !== []) {
			$deckUids = [];
			foreach (GithubProjectService::assigneesOf($gItem) as $login) {
				$uid = $userMap['gh2deck'][strtolower($login)] ?? null;
				if ($uid !== null) {
					$deckUids[] = $uid;
				}
			}
			if ($deckUids !== []) {
				$this->deck->syncAssignees($userId, (int)$card['id'], $deckUids);
			}
		}
		if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_DECK)
			&& ($content['__typename'] ?? '') === 'Issue') {
			$this->pullMissingComments($userId, (int)$card['id'], $gItem);
		}
	}

	private function pullMissingComments(string $userId, int $cardId, array $gItem): void {
		$repo = GithubProjectService::repoOf($gItem);
		$number = GithubProjectService::numberOf($gItem);
		if ($repo === null || $number === null) {
			return;
		}
		$deckComments = $this->deck->getComments($cardId);
		$ghComments = $this->github->getIssueComments($userId, $repo, $number);
		foreach ($ghComments as $gc) {
			$body = trim($gc['body']);
			if ($body === '' || in_array($body, $deckComments, true) || in_array('[Deck] ' . $body, $deckComments, true)) {
				continue;
			}
			$this->deck->addComment($userId, $cardId, '[GitHub ' . $gc['user'] . '] ' . $body);
		}
	}

	public function hashDeck(array $card): string {
		return hash('sha256', implode('|', [
			$card['title'] ?? '', $card['description'] ?? '',
			(string)($card['stackId'] ?? ''), (string)($card['duedate'] ?? ''),
			implode(',', $card['labels'] ?? []), implode(',', $card['assignedUsers'] ?? []),
		]));
	}

	public function hashGithub(array $item): string {
		$c = $item['content'] ?? [];
		return hash('sha256', implode('|', [
			$c['title'] ?? '', $c['body'] ?? '', GithubProjectService::statusOf($item),
			(string)(GithubProjectService::dateOf($item) ?? ''),
			implode(',', GithubProjectService::labelsOf($item)),
			implode(',', GithubProjectService::assigneesOf($item)),
		]));
	}

	public function findMap(int $id, string $userId): BoardMap {
		$map = $this->boardMaps->find($id);
		if ($map->getUserId() !== $userId) {
			throw new DoesNotExistException('Not found');
		}
		return $map;
	}

	/** @return array{gh2deck: array<string,string>, deck2gh: array<string,string>} */
	private function loadUserMap(int $mapId): array {
		$gh2deck = [];
		$deck2gh = [];
		try {
			foreach ($this->userMaps->findByMap($mapId) as $um) {
				$gh2deck[strtolower($um->getGithubLogin())] = $um->getDeckUid();
				$deck2gh[strtolower($um->getDeckUid())] = $um->getGithubLogin();
			}
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: usermap load failed', ['exception' => $e]);
		}
		return ['gh2deck' => $gh2deck, 'deck2gh' => $deck2gh];
	}

	/**
	 * Propagate deletes/archives both ways for linked items missing on one side.
	 * @param array<int,array> $deckCards
	 * @param array<string,array> $githubItems
	 */
	private function propagateDeletions(BoardMap $map, string $userId, array $deckCards, array $githubItems, array $known, bool $allowToGithub, bool $allowToDeck, array $fieldMap): array {
		$stats = ['archived_to_deck' => 0, 'deleted_to_github' => 0, 'deleted_to_deck' => 0];
		$deckIds = [];
		foreach ($deckCards as $c) {
			$deckIds[(int)$c['id']] = true;
		}
		foreach ($known as $key => $link) {
			if (!str_starts_with($key, 'deck:')) {
				continue;
			}
			try {
				$cardId = $link->getDeckCardId();
				$itemId = $link->getGithubItemId();
				$deckGone = !isset($deckIds[$cardId]);
				$ghGone = !isset($githubItems[$itemId]);
				$ghArchived = isset($githubItems[$itemId]) && !empty($githubItems[$itemId]['archivedAt']);
				if ($ghArchived && $allowToDeck && !$deckGone) {
					$this->deck->archiveCard($userId, $cardId, true);
					$stats['archived_to_deck']++;
				} elseif ($ghGone && $allowToDeck && !$deckGone) {
					$this->deck->deleteCard($userId, $cardId);
					$this->itemMaps->delete($link);
					$stats['deleted_to_deck']++;
				} elseif ($deckGone && $allowToGithub && !$ghGone) {
					$gItem = $githubItems[$itemId];
					if (($gItem['content']['__typename'] ?? '') === 'PullRequest') {
						$this->itemMaps->delete($link);
						continue;
					}
					$this->github->archiveItem($userId, $map->getGithubProjectId(), $itemId, true);
					$this->itemMaps->delete($link);
					$stats['deleted_to_github']++;
				}
			} catch (\Throwable $e) {
				$this->logger->debug('deckgithubsync: propagation failed', ['exception' => $e]);
			}
		}
		return $stats;
	}
}
