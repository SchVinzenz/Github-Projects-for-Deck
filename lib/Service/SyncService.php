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
use OCP\IUserManager;
use OCP\IUserSession;
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
		private IUserManager $userManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function syncBoard(BoardMap $map): array {
		$prevUid = $this->userSession->getUser()?->getUID();
		try {
			return $this->doSyncBoard($map);
		} finally {
			if ($this->userSession->getUser()?->getUID() !== $prevUid) {
				$this->userSession->setUser($prevUid === null ? null : $this->userManager->get($prevUid));
			}
		}
	}

	private function doSyncBoard(BoardMap $map): array {
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

		$deckCards = [];
		$stacks = [];
		try {
			$deckCards = $this->deck->getCards($userId, $map->getDeckBoardId());
			$stacks = $this->deck->getStacks($userId, $map->getDeckBoardId());
		} catch (\Throwable $e) {
			$this->logger->warning('deckgithubsync: Deck fetch failed', ['exception' => $e]);
			$stats['errors'][] = 'deck: ' . $e->getMessage();
			return $stats;
		}
		$stackByTitle = [];
		$stackById = [];
		foreach ($stacks as $s) {
			$stackByTitle[strtolower($s['title'])] = $s['id'];
			$stackById[$s['id']] = $s['title'];
		}
		if ($stacks === [] && $allowToDeck) {
			$stats['errors'][] = 'deck: board has no stacks';
			return $stats;
		}

		$options = [];
		$statusFieldId = '';
		$dateFieldId = '';
		$githubItems = [];
		try {
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

			$after = null;
			do {
				$page = $this->github->listItems($userId, $map->getGithubProjectId(), $after);
				foreach ($page['items'] as $it) {
					$githubItems[$it['id']] = $it;
				}
				$after = $page['hasNext'] ? $page['cursor'] : null;
			} while ($after !== null);
		} catch (\Throwable $e) {
			$this->logger->warning('deckgithubsync: GitHub fetch failed', ['exception' => $e]);
			$stats['errors'][] = 'github: ' . $e->getMessage();
			return $stats;
		}

		$userMap = $this->loadUserMap($map->getId()); // githubLogin(lower) => deckUid + reverse

		$known = [];
		foreach ($this->itemMaps->findByMap($map->getId()) as $im) {
			$known['deck:' . $im->getDeckCardId()] = $im;
			$known['gh:' . $im->getGithubItemId()] = $im;
		}

		// Untracked titles for duplicate protection (fresh mappings, both sides)
		$untrackedGhByTitle = [];
		foreach ($githubItems as $itemId => $item) {
			if (!isset($known['gh:' . $itemId])) {
				$t = $this->normTitle($item['content']['title'] ?? '');
				if ($t !== '') {
					$untrackedGhByTitle[$t] = $itemId;
				}
			}
		}
		$untrackedDeckByTitle = [];
		foreach ($deckCards as $card) {
			if (!isset($known['deck:' . $card['id']])) {
				$t = $this->normTitle($card['title'] ?? '');
				if ($t !== '') {
					$untrackedDeckByTitle[$t] = $card['id'];
				}
			}
		}

		// Deck -> GitHub
		if ($allowToGithub) {
			foreach ($deckCards as $card) {
				try {
					$key = 'deck:' . $card['id'];
					$existing = $known[$key] ?? null;
					$hash = $this->hashDeck($card);
					if ($existing !== null && $existing->getDeckHash() === $hash && $hash !== '' && isset($githubItems[$existing->getGithubItemId()])) {
						continue;
					}
					if ($existing !== null && isset($githubItems[$existing->getGithubItemId()])) {
						$gItem = $githubItems[$existing->getGithubItemId()];
						if (($gItem['content']['__typename'] ?? '') === 'PullRequest') {
							continue; // PRs are read-only, never push
						}
						if ($allowToDeck && $allowToGithub && $this->newerSide($card, $gItem) === 'github') {
							$patch = $this->pullGithubToDeck($map, $userId, $card, $gItem, $fieldMap, $stackByTitle, $dateFieldId, $userMap);
							$existing->setGithubHash($this->hashGithub($gItem));
							$existing->setDeckHash($this->hashDeck(array_merge($card, $patch)));
							$this->itemMaps->update($existing);
							$stats['github_to_deck']++;
							continue;
						}
						$this->pushDeckToGithub($map, $userId, $card, $gItem, $fieldMap, $statusFieldId, $options, $stackById, $dateFieldId, $userMap);
						$existing->setDeckHash($hash);
						$this->itemMaps->update($existing);
					} else {
						// Duplicate protection: adopt untracked GitHub item with same title
						$matchId = $untrackedGhByTitle[$this->normTitle($card['title'])] ?? null;
						if ($matchId !== null && isset($githubItems[$matchId])) {
							$link = $this->linkItems($map->getId(), (int)$card['id'], $matchId, $githubItems[$matchId], $hash, $this->hashGithub($githubItems[$matchId]));
							if ($link !== null) {
								$known['deck:' . $card['id']] = $known['gh:' . $matchId] = $link;
								unset($untrackedGhByTitle[$this->normTitle($card['title'])]);
							}
							$stats['deck_to_github']++;
							continue;
						}
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
						$im = $this->linkItems($map->getId(), (int)$card['id'], $itemId, ['content' => ['id' => '', '__typename' => 'DraftIssue']], $hash, '');
						if ($im !== null) {
							$known['deck:' . $card['id']] = $known['gh:' . $itemId] = $im;
						}
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
						if ($this->hashGithub($item) === $link->getGithubHash() && $link->getGithubHash() !== '') {
							continue;
						}
						if ($allowToGithub && $this->newerSide($card, $item) === 'deck') {
							// deck wins, handled in Deck->GitHub pass; record hash to stop re-checking
							$link->setGithubHash($this->hashGithub($item));
							$this->itemMaps->update($link);
							continue;
						}
						$patch = $this->pullGithubToDeck($map, $userId, $card, $item, $fieldMap, $stackByTitle, $dateFieldId, $userMap);
						$link->setGithubHash($this->hashGithub($item));
						if ($patch !== []) {
							$link->setDeckHash($this->hashDeck(array_merge($card, $patch)));
						}
						$this->itemMaps->update($link);
						$stats['github_to_deck']++;
						continue;
					}
					$content = $item['content'] ?? [];
					$title = $content['title'] ?? ('GitHub item ' . substr($itemId, 0, 8));
					// Duplicate protection: adopt untracked Deck card with same title
					$matchCardId = $untrackedDeckByTitle[$this->normTitle($title)] ?? null;
					$matchCard = $matchCardId !== null ? $this->findDeckCard($matchCardId, $deckCards) : null;
					if ($matchCard !== null) {
						$link = $this->linkItems($map->getId(), $matchCardId, $itemId, $item, $this->hashDeck($matchCard), $this->hashGithub($item));
						if ($link !== null) {
							$known['deck:' . $matchCardId] = $known['gh:' . $itemId] = $link;
							unset($untrackedDeckByTitle[$this->normTitle($title)]);
						}
						$stats['github_to_deck']++;
						continue;
					}
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
					$link = $this->linkItems($map->getId(), $cardId, $itemId, $item, $this->hashDeck([
						'title' => $title, 'description' => $body, 'stackId' => $targetStack,
						'duedate' => $due, 'labels' => $ghLabels ?? [], 'assignedUsers' => [],
					]), $this->hashGithub($item));
					if ($link !== null) {
						$known['deck:' . $cardId] = $known['gh:' . $itemId] = $link;
					}
					$stats['github_to_deck']++;
				} catch (\Throwable $e) {
					$stats['errors'][] = 'github item ' . $itemId . ': ' . $e->getMessage();
				}
			}
		}

		$map->setLastSync(time());
		$this->boardMaps->update($map);
		return $stats;
	}

	private function normTitle(string $title): string {
		$t = strtolower(trim($title));
		return $t !== '' && $t !== 'github item' ? $t : '';
	}

	/**
	 * Create an item link unless one already exists (race-safe).
	 * @return ItemMap|null the link, or null when a conflicting link exists
	 */
	private function linkItems(int $mapId, int $deckCardId, string $githubItemId, array $gItem, string $deckHash, string $githubHash): ?ItemMap {
		try {
			if ($this->itemMaps->findByDeckCard($mapId, $deckCardId) !== null
				|| $this->itemMaps->findByGithubItem($mapId, $githubItemId) !== null) {
				return $this->itemMaps->findByDeckCard($mapId, $deckCardId)
					?? $this->itemMaps->findByGithubItem($mapId, $githubItemId);
			}
			$content = $gItem['content'] ?? [];
			$im = new ItemMap();
			$im->setMapId($mapId);
			$im->setDeckCardId($deckCardId);
			$im->setGithubItemId($githubItemId);
			$im->setGithubContentId($content['id'] ?? '');
			$im->setContentType($content['__typename'] ?? 'DraftIssue');
			$im->setSyncHash($deckHash !== '' ? $deckHash : $githubHash);
			$im->setDeckHash($deckHash);
			$im->setGithubHash($githubHash);
			$this->itemMaps->insert($im);
			return $im;
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: link already exists', ['map' => $mapId, 'card' => $deckCardId]);
			try {
				return $this->itemMaps->findByDeckCard($mapId, $deckCardId)
					?? $this->itemMaps->findByGithubItem($mapId, $githubItemId);
			} catch (\Throwable) {
				return null;
			}
		}
	}

	private function pushDeckToGithub(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, string $statusFieldId, array $options, array $stackById = [], string $dateFieldId = '', array $userMap = []): void {
		$content = $gItem['content'] ?? [];
		$type = $content['__typename'] ?? 'DraftIssue';
		if ($type === 'PullRequest') {
			return; // read-only
		}
		if ($type === 'DraftIssue') {
			$titleAllowed = $this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_GITHUB);
			$bodyAllowed = $this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_GITHUB);
			$titleChanged = $titleAllowed && ($content['title'] ?? null) !== $card['title'];
			$bodyChanged = $bodyAllowed && ($content['body'] ?? null) !== ($card['description'] ?? '');
			if ($titleChanged || $bodyChanged) {
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

	private function pullGithubToDeck(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, array $stackByTitle, string $dateFieldId = '', array $userMap = []): array {
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
		return $patch;
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
		$map = $this->boardMaps->findById($id);
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
}
