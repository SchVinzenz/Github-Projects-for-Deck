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
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Bidirectional sync with per-board direction + per-field directions.
 * Loop protection: sync hash over mapped fields + updatedAt comparison.
 */
class SyncService {
	public function __construct(
		private BoardMapMapper $boardMaps,
		private ItemMapMapper $itemMaps,
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
		$options = $fields['options']; // name => id

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
						if ($allowToDeck && $allowToGithub && $this->newerSide($card, $gItem) === 'github') {
							$this->pullGithubToDeck($map, $userId, $card, $gItem, $fieldMap, $stackByTitle);
							$existing->setSyncHash($this->hashDeck($this->findDeckCard($card['id'], $deckCards) ?? $card));
							$this->itemMaps->update($existing);
							$stats['github_to_deck']++;
							continue;
						}
						$this->pushDeckToGithub($map, $userId, $card, $gItem, $fieldMap, $statusFieldId, $options, $stackById);
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
						$this->pullGithubToDeck($map, $userId, $card, $item, $fieldMap, $stackByTitle);
						$link->setSyncHash($this->hashGithub($item));
						$this->itemMaps->update($link);
						$stats['github_to_deck']++;
						continue;
					}
					$content = $item['content'] ?? [];
					$title = $content['title'] ?? ('GitHub item ' . substr($itemId, 0, 8));
					$body = $content['body'] ?? '';
					$status = GithubProjectService::statusOf($item);
					$targetStack = $stackByTitle[strtolower($status)] ?? reset($stacks)['id'] ?? null;
					if ($targetStack === null) {
						continue;
					}
					$cardId = $this->deck->createCard($userId, (int)$targetStack, $title, $body);
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

		$map->setLastSync(time());
		$this->boardMaps->update($map);
		return $stats;
	}

	private function pushDeckToGithub(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, string $statusFieldId, array $options, array $stackById = []): void {
		$content = $gItem['content'] ?? [];
		$type = $content['__typename'] ?? 'DraftIssue';
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
				if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_GITHUB)) {
					$this->pushMissingComments($userId, $card, $repo, $number);
				}
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

	private function pullGithubToDeck(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, array $stackByTitle): void {
		$content = $gItem['content'] ?? [];
		$patch = [];
		if ($this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_DECK) && isset($content['title']) && $content['title'] !== $card['title']) {
			$patch['title'] = $content['title'];
		}
		if ($this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_DECK) && isset($content['body']) && $content['body'] !== ($card['description'] ?? '')) {
			$patch['description'] = $content['body'];
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
			implode(',', $card['labels'] ?? []),
		]));
	}

	public function hashGithub(array $item): string {
		$c = $item['content'] ?? [];
		return hash('sha256', implode('|', [
			$c['title'] ?? '', $c['body'] ?? '', GithubProjectService::statusOf($item),
		]));
	}

	public function findMap(int $id, string $userId): BoardMap {
		$map = $this->boardMaps->find($id);
		if ($map->getUserId() !== $userId) {
			throw new DoesNotExistException('Not found');
		}
		return $map;
	}
}
