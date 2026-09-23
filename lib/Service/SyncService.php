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
use OCP\IL10N;
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
		private IL10N $l,
	) {
	}

	/** Non-critical sync problems collected during a run (best-effort fields). */
	private array $warnings = [];

	/** True when field IDs were (re)assigned this run: hashes may be stale. */
	private bool $schemaChanged = false;

	/** True when preexisting links with hashes exist (stale-schema bypass applies). */
	private bool $hasEstablishedLinks = false;

	/**
	 * Run a best-effort sync step: failures are recorded as warnings and do
	 * not abort the item sync (unlike title/status/date failures).
	 */
	private function bestEffort(callable $step, string $context): void {
		try {
			$step();
		} catch (\Throwable $e) {
			if ($e instanceof GithubRateLimitException) {
				throw $e;
			}
			$this->warnings[] = $context . ': ' . $e->getMessage();
			$this->logger->warning('deckgithubsync: ' . $context, ['exception' => $e]);
		}
	}

	public function syncBoard(BoardMap $map): array {
		$prevUid = $this->userSession->getUser()?->getUID();
		try {
			return $this->doSyncBoard($map);
		} catch (GithubRateLimitException $e) {
			// Cooldown instead of lastSync: lastSync keeps the last SUCCESSFUL
			// run, cooldown_until keeps cron and webhooks from hammering GitHub.
			$map->setCooldownUntil($e->getRetryAt());
			$this->boardMaps->update($map);
			return ['deck_to_github' => 0, 'github_to_deck' => 0, 'errors' => [$e->getMessage()], 'warnings' => $this->warnings, 'retryAt' => $e->getRetryAt()];
		} finally {
			if ($this->userSession->getUser()?->getUID() !== $prevUid) {
				$this->userSession->setUser($prevUid === null ? null : $this->userManager->get($prevUid));
			}
		}
	}

	private function doSyncBoard(BoardMap $map): array {
		$userId = $map->getUserId();
		$stats = ['deck_to_github' => 0, 'github_to_deck' => 0, 'errors' => [], 'warnings' => []];
		$this->warnings = [];
		$this->schemaChanged = false;
		$this->hasEstablishedLinks = false;
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
			if ($e instanceof GithubRateLimitException) {
				throw $e;
			}
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
		$links = $this->itemMaps->findByMap($map->getId());
		$known = [];
		foreach ($links as $im) {
			$known['deck:' . $im->getDeckCardId()] = $im;
			$known['gh:' . $im->getGithubItemId()] = $im;
		}
		// Links predating this run whose hashes were computed under a previous
		// schema (adopted field IDs); only those need hash-skip bypasses.
		$preexisting = array_fill_keys(array_keys($known), true);
		foreach ($known as $link) {
			if (($link->getDeckHash() ?? '') !== '' || ($link->getGithubHash() ?? '') !== '') {
				$this->hasEstablishedLinks = true;
				break;
			}
		}
		$incremental = $this->canSyncIncrementally($map, $deckCards, $known, $fieldMap, $allowToGithub);
		$filter = $incremental ? 'updated:>@today-1d' : null;

		$options = [];
		$statusFieldId = '';
		$dateFieldId = '';
		$startFieldId = '';
		$githubItems = [];
		try {
			$fields = $this->github->getFields($userId, $map->getGithubProjectId());
			$knownIds = [];
			foreach ($fields['fields'] ?? [] as $f) {
				if (isset($f['id'])) {
					$knownIds[(string)$f['id']] = true;
				}
			}
			// Field IDs go stale when a field is deleted and recreated; adopt the
			// detected ID, but only when the stored one no longer exists (never
			// clobber a deliberate override of a still-existing field).
			$statusFieldId = $this->refreshFieldId($map, 'status', $map->getStatusFieldId(), (string)($fields['statusFieldId'] ?? ''), $knownIds);
			$dateFieldId = $this->refreshFieldId($map, 'due', $map->getDateFieldId(), (string)($fields['dateFieldId'] ?? ''), $knownIds);
			$startFieldId = $this->refreshFieldId($map, 'start', $map->getStartFieldId(), (string)($fields['startDateFieldId'] ?? ''), $knownIds);
			if ($dateFieldId !== '' && $dateFieldId === ($fields['startDateFieldId'] ?? '') && ($fields['dateFieldId'] ?? '') !== '') {
				$dateFieldId = $fields['dateFieldId'];
			}
			if ($allowToGithub) {
				try {
					$dateFields = $this->github->ensureDateFields($userId, $map->getGithubProjectId(), $startFieldId, $dateFieldId);
					if ($dateFields['startDateFieldId'] !== $startFieldId || $dateFields['dateFieldId'] !== $dateFieldId) {
						$map->setStartFieldId($dateFields['startDateFieldId']);
						$map->setDateFieldId($dateFields['dateFieldId']);
						if ($this->hasEstablishedLinks) {
							$this->schemaChanged = true;
						}
					}
					$startFieldId = $dateFields['startDateFieldId'];
					$dateFieldId = $dateFields['dateFieldId'];
				} catch (\Throwable $e) {
					if ($e instanceof GithubRateLimitException) {
						throw $e;
					}
					$this->warnings[] = $this->l->t('Date fields: %s', [$e->getMessage()]);
					$this->logger->warning('deckgithubsync: ensureDateFields failed', ['exception' => $e]);
				}
			}
			$options = $fields['options']; // name => id
			if ($statusFieldId !== '') {
				if ($allowToGithub) {
					try {
						$options = $this->github->ensureStatusOptions($userId, $statusFieldId, $fields['fields'] ?? [], array_values($stackById));
					} catch (\Throwable $e) {
						if ($e instanceof GithubRateLimitException) {
							throw $e;
						}
						$this->warnings[] = $this->l->t('Status options: %s', [$e->getMessage()]);
						$this->logger->warning('deckgithubsync: ensureStatusOptions failed', ['exception' => $e]);
					}
				}
				if ($allowToDeck) {
					foreach (array_keys($fields['options']) as $statusName) {
						$key = strtolower($statusName);
						if (!isset($stackByTitle[$key])) {
							try {
								$id = $this->deck->createStack($userId, $map->getDeckBoardId(), $statusName, count($stacks) * 100);
							} catch (\Throwable $e) {
								$this->warnings[] = $this->l->t('Create stack: %s', [$e->getMessage()]);
								$this->logger->warning('deckgithubsync: createStack failed', ['exception' => $e]);
								continue;
							}
							$stacks[] = ['id' => $id, 'title' => $statusName];
							$stackByTitle[$key] = $id;
							$stackById[$id] = $statusName;
						}
					}
				}
			} elseif ($allowToGithub) {
				$this->warnings[] = $this->l->t('GitHub Project has no Status field; stack sync is disabled.');
				$this->logger->warning('deckgithubsync: project has no Status field, stack sync disabled');
			}
			if ($stacks === [] && $allowToDeck) {
				try {
					$id = $this->deck->createStack($userId, $map->getDeckBoardId(), 'Todo', 0);
				} catch (\Throwable $e) {
					$this->warnings[] = $this->l->t('Create stack: %s', [$e->getMessage()]);
					$this->logger->warning('deckgithubsync: createStack failed', ['exception' => $e]);
					$stats['warnings'] = $this->warnings;
					return $stats;
				}
				$stacks[] = ['id' => $id, 'title' => 'Todo'];
				$stackByTitle['todo'] = $id;
				$stackById[$id] = 'Todo';
			}

			$after = null;
			do {
				$page = $this->github->listItems($userId, $map->getGithubProjectId(), $after, $filter);
				foreach ($page['items'] as $it) {
					$githubItems[$it['id']] = $it;
				}
				$after = $page['hasNext'] ? $page['cursor'] : null;
			} while ($after !== null);
		} catch (\Throwable $e) {
			if ($e instanceof GithubRateLimitException) {
				throw $e;
			}
			$this->logger->warning('deckgithubsync: GitHub fetch failed', ['exception' => $e]);
			$stats['errors'][] = 'github: ' . $e->getMessage();
			return $stats;
		}

		$userMap = $this->loadUserMap($map->getId()); // githubLogin(lower) => deckUid + reverse

		$skipGithubItems = [];

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
					if ($existing !== null && !isset($githubItems[$existing->getGithubItemId()])) {
						if (!$incremental) {
							$this->warnings[] = $this->l->t('Linked GitHub item is missing from the project; Deck card %s was skipped.', [$card['id']]);
						}
						continue;
					}
					$hash = $this->hashDeck($card);
					$staleSchema = $existing !== null && $this->schemaChanged && isset($preexisting[$key]) && ($existing->getDeckHash() ?? '') !== '';
					if (!$staleSchema && $existing !== null && $existing->getDeckHash() === $hash && $hash !== '' && isset($githubItems[$existing->getGithubItemId()])
						&& !($map->getGithubRepository() !== '' && ($githubItems[$existing->getGithubItemId()]['content']['__typename'] ?? '') === 'DraftIssue')) {
						$unchangedItem = $githubItems[$existing->getGithubItemId()];
						if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_GITHUB)
							&& ($unchangedItem['content']['__typename'] ?? '') === 'Issue') {
							$repo = GithubProjectService::repoOf($unchangedItem);
							$number = GithubProjectService::numberOf($unchangedItem);
							if ($repo !== null && $number !== null) {
								$this->pushMissingComments($userId, $card, $repo, $number);
							}
						}
						continue;
					}
					if ($existing !== null && isset($githubItems[$existing->getGithubItemId()])) {
						$gItem = $githubItems[$existing->getGithubItemId()];
						if (($gItem['content']['__typename'] ?? '') === 'DraftIssue' && $map->getGithubRepository() !== '') {
							$existing->setSyncHash('pending_draft');
							$this->itemMaps->update($existing);
							$oldId = $gItem['id'];
							$gItem = $this->github->convertDraftToIssue($userId, $map->getGithubProjectId(), $oldId, $map->getGithubRepository());
							$skipGithubItems[$oldId] = true;
							$skipGithubItems[$gItem['id']] = true;
							$existing->setGithubItemId($gItem['id']);
							$existing->setGithubContentId($gItem['content']['id'] ?? '');
							$existing->setContentType('Issue');
							$this->itemMaps->update($existing);
							unset($githubItems[$oldId], $known['gh:' . $oldId]);
							$githubItems[$gItem['id']] = $gItem;
							$known['gh:' . $gItem['id']] = $existing;
						}
						if (($gItem['content']['__typename'] ?? '') === 'PullRequest') {
							continue; // PRs are read-only, never push
						}
						if ($existing->getSyncHash() === 'pending_draft') {
							$this->pushDeckToGithub($map, $userId, $card, $gItem, $fieldMap, $statusFieldId, $options, $stackById, $dateFieldId, $userMap, $startFieldId);
							$existing->setDeckHash($hash);
							$existing->setGithubHash($this->hashGithub($gItem));
							$existing->setSyncHash('');
							$this->itemMaps->update($existing);
							$stats['deck_to_github']++;
							continue;
						}
						if ($allowToDeck && $allowToGithub && $this->newerSide($card, $gItem) === 'github') {
							$patch = $this->pullGithubToDeck($map, $userId, $card, $gItem, $fieldMap, $stackByTitle, $dateFieldId, $userMap, $statusFieldId, $startFieldId);
							$existing->setGithubHash($this->hashGithub($gItem));
							$existing->setDeckHash($this->hashDeck(array_merge($card, $patch)));
							$this->itemMaps->update($existing);
							$stats['github_to_deck']++;
							continue;
						}
						$this->pushDeckToGithub($map, $userId, $card, $gItem, $fieldMap, $statusFieldId, $options, $stackById, $dateFieldId, $userMap, $startFieldId);
						$existing->setDeckHash($hash);
						$this->itemMaps->update($existing);
					} else {
						// Duplicate protection: adopt untracked GitHub item with same title
						$matchId = $untrackedGhByTitle[$this->normTitle($card['title'])] ?? null;
						if ($matchId !== null && isset($githubItems[$matchId])) {
							// Matching titles identify the same item, but do not prove equal content.
							$link = $this->linkItems($map->getId(), (int)$card['id'], $matchId, $githubItems[$matchId], '', '');
							if ($link !== null) {
								$known['deck:' . $card['id']] = $known['gh:' . $matchId] = $link;
								unset($untrackedGhByTitle[$this->normTitle($card['title'])]);
							}
							$stats['deck_to_github']++;
							continue;
						}
						$itemId = $this->github->addDraft($userId, $map->getGithubProjectId(), $card['title'], $card['description']);
						if ($itemId === null) {
							throw new \RuntimeException('GitHub did not return an item ID for the new draft');
						}
						// Persist the link before optional field updates so a failed update cannot create a duplicate draft on retry.
						$im = $this->linkItems($map->getId(), (int)$card['id'], $itemId, ['content' => ['id' => '', '__typename' => 'DraftIssue']], '', '', true);
						if ($im === null) {
							throw new \RuntimeException('Could not link the newly created GitHub draft');
						}
						$known['deck:' . $card['id']] = $known['gh:' . $itemId] = $im;
						if ($map->getGithubRepository() !== '') {
							$issueItem = $this->github->convertDraftToIssue($userId, $map->getGithubProjectId(), $itemId, $map->getGithubRepository());
							$itemId = $issueItem['id'];
							$im->setGithubItemId($itemId);
							$im->setGithubContentId($issueItem['content']['id'] ?? '');
							$im->setContentType('Issue');
							$this->itemMaps->update($im);
							$this->pushDeckToGithub($map, $userId, $card, $issueItem, $fieldMap, $statusFieldId, $options, $stackById, $dateFieldId, $userMap, $startFieldId);
							$im->setDeckHash($hash);
							$im->setSyncHash('');
							$this->itemMaps->update($im);
							$stats['deck_to_github']++;
							continue;
						}
						$stackTitle = $stackById[$card['stackId']] ?? '';
						if ($this->fieldAllowed($fieldMap, 'status', BoardMap::DIR_TO_GITHUB) && $stackTitle !== '' && isset($options[mb_strtolower($stackTitle)]) && $statusFieldId !== '') {
							$this->github->setStatus($userId, $map->getGithubProjectId(), $itemId, $statusFieldId, $options[mb_strtolower($stackTitle)]);
						}
						if ($this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_GITHUB) && $dateFieldId !== '') {
							$deckDue = DeckService::normalizeDue($card['duedate'] ?? null);
							if ($deckDue !== null) {
								$this->github->setDate($userId, $map->getGithubProjectId(), $itemId, $dateFieldId, $deckDue);
							}
						}
						if ($this->fieldAllowed($fieldMap, 'start', BoardMap::DIR_TO_GITHUB) && $startFieldId !== '') {
							$deckStart = DeckService::normalizeDue($card['startdate'] ?? null);
							if ($deckStart !== null) {
								$this->github->setDate($userId, $map->getGithubProjectId(), $itemId, $startFieldId, $deckStart);
							}
						}
						$im->setDeckHash($hash);
						$im->setSyncHash('');
						$this->itemMaps->update($im);
					}
					$stats['deck_to_github']++;
				} catch (\Throwable $e) {
					if ($e instanceof GithubRateLimitException) {
						throw $e;
					}
					$stats['errors'][] = 'deck card ' . $card['id'] . ': ' . $e->getMessage();
				}
			}
		}

		// GitHub -> Deck (new + updates for known items)
		if ($allowToDeck) {
			foreach ($githubItems as $itemId => $item) {
				if (isset($skipGithubItems[$itemId])) {
					continue;
				}
				try {
					$link = $known['gh:' . $itemId] ?? null;
					if ($link !== null) {
						$card = $this->findDeckCard($link->getDeckCardId(), $deckCards);
						if ($card === null) {
							continue;
						}
						$staleSchema = $this->schemaChanged && isset($preexisting['gh:' . $itemId]) && ($link->getGithubHash() ?? '') !== '';
						if (!$staleSchema && $this->hashGithub($item) === $link->getGithubHash() && $link->getGithubHash() !== '') {
							if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_DECK)
								&& ($item['content']['__typename'] ?? '') === 'Issue') {
								$this->pullMissingComments($userId, (int)$card['id'], $item);
							}
							continue;
						}
						if ($allowToGithub && $this->newerSide($card, $item) === 'deck') {
							// deck wins, handled in Deck->GitHub pass; record hash to stop re-checking
							$link->setGithubHash($this->hashGithub($item));
							$this->itemMaps->update($link);
							continue;
						}
						$patch = $this->pullGithubToDeck($map, $userId, $card, $item, $fieldMap, $stackByTitle, $dateFieldId, $userMap, $statusFieldId, $startFieldId);
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
						$link = $this->linkItems($map->getId(), $matchCardId, $itemId, $item, '', '');
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
					$status = GithubProjectService::statusOf($item, $statusFieldId !== '' ? $statusFieldId : null);
					$targetStack = $stackByTitle[strtolower($status)] ?? reset($stacks)['id'] ?? null;
					if ($targetStack === null) {
						continue;
					}
					$due = $this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_DECK) && $dateFieldId !== '' ? GithubProjectService::dateOf($item, $dateFieldId) : null;
					$start = $this->fieldAllowed($fieldMap, 'start', BoardMap::DIR_TO_DECK) && $startFieldId !== '' ? GithubProjectService::dateOf($item, $startFieldId) : null;
					$cardId = $this->deck->createCard($userId, (int)$targetStack, $title, $body, $due, $start);
					if (($content['__typename'] ?? '') === 'Issue' && !empty($content['closed'])) {
						$this->deck->setDone($userId, $cardId, true);
					}
					if ($this->fieldAllowed($fieldMap, 'labels', BoardMap::DIR_TO_DECK)) {
						$ghLabels = GithubProjectService::labelsOf($item);
						$this->bestEffort(fn () => $this->deck->syncLabels($userId, $map->getDeckBoardId(), $cardId, $ghLabels, !($content['labels']['pageInfo']['hasNextPage'] ?? false)), 'labels GitHub→Deck');
					}
					if ($this->fieldAllowed($fieldMap, 'assignees', BoardMap::DIR_TO_DECK) && $userMap !== []) {
						$deckUids = [];
						foreach (GithubProjectService::assigneesOf($item) as $login) {
							$uid = $userMap['gh2deck'][strtolower($login)] ?? null;
							if ($uid !== null) {
								$deckUids[] = $uid;
							}
						}
						$this->bestEffort(fn () => $this->deck->syncAssignees($userId, $cardId, $deckUids, ($content['assignees']['pageInfo']['hasNextPage'] ?? false) ? [] : array_values($userMap['gh2deck'])), 'assignees GitHub→Deck');
					}
					if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_DECK) && ($content['__typename'] ?? '') === 'Issue') {
						$this->bestEffort(fn () => $this->pullMissingComments($userId, $cardId, $item), 'comments GitHub→Deck');
					}
					$link = $this->linkItems($map->getId(), $cardId, $itemId, $item, $this->hashDeck([
						'title' => $title, 'description' => $body, 'stackId' => $targetStack,
						'duedate' => $due, 'startdate' => $start, 'done' => !empty($content['closed']), 'labels' => $ghLabels ?? [], 'assignedUsers' => [],
					]), $this->hashGithub($item));
					if ($link !== null) {
						$known['deck:' . $cardId] = $known['gh:' . $itemId] = $link;
					}
					$stats['github_to_deck']++;
				} catch (\Throwable $e) {
					if ($e instanceof GithubRateLimitException) {
						throw $e;
					}
					$stats['errors'][] = 'github item ' . $itemId . ': ' . $e->getMessage();
				}
			}
		}

		if ($stats['errors'] === []) {
			$this->saveCommentHashes($links, $deckCards, $fieldMap, $allowToGithub);
			$map->setLastSync(time());
			$map->setCooldownUntil(0);
		}
		$stats['warnings'] = $this->warnings;
		$this->boardMaps->update($map);
		return $stats;
	}

	private function canSyncIncrementally(BoardMap $map, array $deckCards, array $known, array $fieldMap, bool $allowToGithub): bool {
		$lastSync = $map->getLastSync();
		if ($lastSync <= 0 || gmdate('Y-m-d', $lastSync) !== gmdate('Y-m-d')) {
			return false;
		}
		foreach ($deckCards as $card) {
			$link = $known['deck:' . $card['id']] ?? null;
			if ($link === null || $link->getSyncHash() === 'pending_draft') {
				return false;
			}
			if ($allowToGithub && $link->getDeckHash() !== $this->hashDeck($card)) {
				return false;
			}
			if ($allowToGithub && $link->getContentType() === 'Issue'
				&& $this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_GITHUB)
				&& $link->getSyncHash() !== $this->commentHash((int)$card['id'])) {
				return false;
			}
		}
		return true;
	}

	private function commentHash(int $cardId): string {
		return 'comments:' . hash('sha256', json_encode($this->deck->getComments($cardId)) ?: '[]');
	}

	private function saveCommentHashes(array $links, array $deckCards, array $fieldMap, bool $allowToGithub): void {
		if (!$allowToGithub || !$this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_GITHUB)) {
			return;
		}
		$cardIds = array_fill_keys(array_column($deckCards, 'id'), true);
		foreach ($links as $link) {
			if ($link->getContentType() !== 'Issue' || !isset($cardIds[$link->getDeckCardId()]) || $link->getSyncHash() === 'pending_draft') {
				continue;
			}
			$hash = $this->commentHash($link->getDeckCardId());
			if ($link->getSyncHash() !== $hash) {
				$link->setSyncHash($hash);
				$this->itemMaps->update($link);
			}
		}
	}

	private function normTitle(string $title): string {
		$t = strtolower(trim($title));
		return $t !== '' && $t !== 'github item' ? $t : '';
	}

	/**
	 * Create an item link unless one already exists (race-safe).
	 * @return ItemMap|null the link, or null when a conflicting link exists
	 */
	private function linkItems(int $mapId, int $deckCardId, string $githubItemId, array $gItem, string $deckHash, string $githubHash, bool $pendingDraft = false): ?ItemMap {
		try {
			$byCard = $this->itemMaps->findByDeckCard($mapId, $deckCardId);
			$byItem = $this->itemMaps->findByGithubItem($mapId, $githubItemId);
			if ($byCard !== null || $byItem !== null) {
				$link = $byCard ?? $byItem;
				return $link->getDeckCardId() === $deckCardId && $link->getGithubItemId() === $githubItemId ? $link : null;
			}
			$content = $gItem['content'] ?? [];
			$im = new ItemMap();
			$im->setMapId($mapId);
			$im->setDeckCardId($deckCardId);
			$im->setGithubItemId($githubItemId);
			$im->setGithubContentId($content['id'] ?? '');
			$im->setContentType($content['__typename'] ?? 'DraftIssue');
			$im->setSyncHash($pendingDraft ? 'pending_draft' : ($deckHash !== '' ? $deckHash : $githubHash));
			$im->setDeckHash($deckHash);
			$im->setGithubHash($githubHash);
			$this->itemMaps->insert($im);
			return $im;
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: link already exists', ['map' => $mapId, 'card' => $deckCardId]);
			try {
				$link = $this->itemMaps->findByDeckCard($mapId, $deckCardId)
					?? $this->itemMaps->findByGithubItem($mapId, $githubItemId);
				return $link !== null && $link->getDeckCardId() === $deckCardId && $link->getGithubItemId() === $githubItemId ? $link : null;
			} catch (\Throwable) {
				return null;
			}
		}
	}

	private function pushDeckToGithub(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, string $statusFieldId, array $options, array $stackById = [], string $dateFieldId = '', array $userMap = [], string $startFieldId = ''): void {
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
				$this->github->updateDraft($userId, $gItem['id'], $content['id'] ?? '',
					$titleAllowed ? $card['title'] : ($content['title'] ?? ''),
					$bodyAllowed ? ($card['description'] ?? '') : ($content['body'] ?? ''));
			}
		} elseif ($type === 'Issue') {
			$patch = [];
			if ($this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_GITHUB) && ($content['title'] ?? '') !== $card['title']) {
				$patch['title'] = $card['title'];
			}
			if ($this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_GITHUB) && ($content['body'] ?? '') !== ($card['description'] ?? '')) {
				$patch['body'] = $card['description'] ?? '';
			}
			if ((bool)($content['closed'] ?? false) !== (bool)($card['done'] ?? false)) {
				$patch['state'] = !empty($card['done']) ? 'closed' : 'open';
			}
			$repo = GithubProjectService::repoOf($gItem);
			$number = GithubProjectService::numberOf($gItem);
			if ($patch !== [] && $repo !== null && $number !== null) {
				$this->github->updateIssueRest($userId, $repo, $number, $patch);
			}
			if ($repo !== null && $number !== null) {
				if ($this->fieldAllowed($fieldMap, 'labels', BoardMap::DIR_TO_GITHUB)) {
					if ($content['labels']['pageInfo']['hasNextPage'] ?? false) {
						throw new \RuntimeException('GitHub issue has more than 100 labels; cannot safely replace labels');
					}
					$ghLabels = GithubProjectService::labelsOf($gItem);
					if (array_diff($card['labels'] ?? [], $ghLabels) !== [] || array_diff($ghLabels, $card['labels'] ?? []) !== []) {
						$this->bestEffort(fn () => $this->github->setIssueLabels($userId, $repo, $number, $card['labels'] ?? []), 'labels Deck→GitHub');
					}
				}
				if ($this->fieldAllowed($fieldMap, 'assignees', BoardMap::DIR_TO_GITHUB) && $userMap !== []) {
					if ($content['assignees']['pageInfo']['hasNextPage'] ?? false) {
						throw new \RuntimeException('GitHub issue has more than 100 assignees; cannot safely replace assignees');
					}
					$ghLogins = array_map('strtolower', GithubProjectService::assigneesOf($gItem));
					$wantLogins = [];
					foreach ($card['assignedUsers'] ?? [] as $uid) {
						$login = $userMap['deck2gh'][strtolower((string)$uid)] ?? null;
						if ($login !== null) {
							$wantLogins[] = strtolower($login);
						}
					}
					if (array_diff($wantLogins, $ghLogins) !== [] || array_diff($ghLogins, $wantLogins) !== []) {
						$this->bestEffort(fn () => $this->github->setIssueAssignees($userId, $repo, $number, array_values(array_unique($wantLogins))), 'assignees Deck→GitHub');
					}
				}
				if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_GITHUB)) {
					$this->bestEffort(fn () => $this->pushMissingComments($userId, $card, $repo, $number), 'comments Deck→GitHub');
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
		$startFieldId = $startFieldId !== '' ? $startFieldId : (string)($map->getStartFieldId() ?? '');
		if ($this->fieldAllowed($fieldMap, 'start', BoardMap::DIR_TO_GITHUB) && $startFieldId !== '') {
			$deckStart = DeckService::normalizeDue($card['startdate'] ?? null);
			$ghStart = GithubProjectService::dateOf($gItem, $startFieldId);
			if ($deckStart !== $ghStart) {
				$this->github->setDate($userId, $map->getGithubProjectId(), $gItem['id'], $startFieldId, $deckStart);
			}
		}
		$stackTitle = $stackById[$card['stackId']] ?? '';
		$currentStatus = GithubProjectService::statusOf($gItem, $statusFieldId !== '' ? $statusFieldId : null);
		if ($this->fieldAllowed($fieldMap, 'status', BoardMap::DIR_TO_GITHUB)
			&& $stackTitle !== '' && $stackTitle !== $currentStatus
			&& isset($options[mb_strtolower($stackTitle)]) && $statusFieldId !== '') {
			$this->github->setStatus($userId, $map->getGithubProjectId(), $gItem['id'], $statusFieldId, $options[mb_strtolower($stackTitle)]);
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
			if (str_starts_with($msg, '[GitHub ') || str_starts_with($msg, '[Deck] ')) {
				continue;
			}
			if (!in_array('[Deck] ' . trim($msg), $ghBodies, true)) {
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

	private function pullGithubToDeck(BoardMap $map, string $userId, array $card, array $gItem, array $fieldMap, array $stackByTitle, string $dateFieldId = '', array $userMap = [], string $statusFieldId = '', string $startFieldId = ''): array {
		$content = $gItem['content'] ?? [];
		$patch = [];
		if ($this->fieldAllowed($fieldMap, 'title', BoardMap::DIR_TO_DECK) && isset($content['title']) && $content['title'] !== $card['title']) {
			$patch['title'] = $content['title'];
		}
		$body = (string)($content['body'] ?? '');
		if (($content['__typename'] ?? '') === 'PullRequest') {
			$body .= "\n\n[GitHub PR, read-only: " . ($content['url'] ?? '') . ']';
		}
		if ($this->fieldAllowed($fieldMap, 'description', BoardMap::DIR_TO_DECK) && $body !== ($card['description'] ?? '')) {
			$patch['description'] = $body;
		}
		if ($this->fieldAllowed($fieldMap, 'due', BoardMap::DIR_TO_DECK) && $dateFieldId !== '') {
			$ghDue = GithubProjectService::dateOf($gItem, $dateFieldId);
			$deckDue = DeckService::normalizeDue($card['duedate'] ?? null);
			if ($ghDue !== $deckDue) {
				$patch['duedate'] = $ghDue;
			}
		}
		$startFieldId = $startFieldId !== '' ? $startFieldId : (string)($map->getStartFieldId() ?? '');
		if ($this->fieldAllowed($fieldMap, 'start', BoardMap::DIR_TO_DECK) && $startFieldId !== '') {
			$ghStart = GithubProjectService::dateOf($gItem, $startFieldId);
			$deckStart = DeckService::normalizeDue($card['startdate'] ?? null);
			if ($ghStart !== $deckStart) {
				$patch['startdate'] = $ghStart;
			}
		}
		if ($this->fieldAllowed($fieldMap, 'status', BoardMap::DIR_TO_DECK)) {
			$status = GithubProjectService::statusOf($gItem, $statusFieldId !== '' ? $statusFieldId : null);
			if ($status !== '' && isset($stackByTitle[strtolower($status)]) && (int)$stackByTitle[strtolower($status)] !== (int)$card['stackId']) {
				$patch['stackId'] = (int)$stackByTitle[strtolower($status)];
			}
		}
		if ($patch !== []) {
			$this->deck->updateCard($userId, (int)$card['id'], $patch);
		}
		if (($content['__typename'] ?? '') === 'Issue' && (bool)($card['done'] ?? false) !== (bool)($content['closed'] ?? false)) {
			$this->deck->setDone($userId, (int)$card['id'], (bool)$content['closed']);
			$patch['done'] = (bool)$content['closed'];
		}
		if ($this->fieldAllowed($fieldMap, 'labels', BoardMap::DIR_TO_DECK)) {
			$ghLabels = GithubProjectService::labelsOf($gItem);
			$this->bestEffort(fn () => $this->deck->syncLabels($userId, $map->getDeckBoardId(), (int)$card['id'], $ghLabels, !($content['labels']['pageInfo']['hasNextPage'] ?? false)), 'labels GitHub→Deck');
		}
		if ($this->fieldAllowed($fieldMap, 'assignees', BoardMap::DIR_TO_DECK) && $userMap !== []) {
			$deckUids = [];
			foreach (GithubProjectService::assigneesOf($gItem) as $login) {
				$uid = $userMap['gh2deck'][strtolower($login)] ?? null;
				if ($uid !== null) {
					$deckUids[] = $uid;
				}
			}
			$this->bestEffort(fn () => $this->deck->syncAssignees($userId, (int)$card['id'], $deckUids, ($content['assignees']['pageInfo']['hasNextPage'] ?? false) ? [] : array_values($userMap['gh2deck'])), 'assignees GitHub→Deck');
		}
		if ($this->fieldAllowed($fieldMap, 'comments', BoardMap::DIR_TO_DECK)
			&& ($content['__typename'] ?? '') === 'Issue') {
			$this->bestEffort(fn () => $this->pullMissingComments($userId, (int)$card['id'], $gItem), 'comments GitHub→Deck');
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
			if ($body === '' || str_starts_with($body, '[Deck] ') || in_array('[GitHub ' . $gc['user'] . '] ' . $body, $deckComments, true)) {
				continue;
			}
			$this->deck->addComment($userId, $cardId, '[GitHub ' . $gc['user'] . '] ' . $body);
		}
	}

	public function hashDeck(array $card): string {
		return hash('sha256', implode('|', [
			$card['title'] ?? '', $card['description'] ?? '',
			(string)($card['stackId'] ?? ''), (string)($card['duedate'] ?? ''), (string)($card['startdate'] ?? ''), (string)(int)($card['done'] ?? false),
			implode(',', $card['labels'] ?? []), implode(',', $card['assignedUsers'] ?? []),
		]));
	}

	public function hashGithub(array $item): string {
		$c = $item['content'] ?? [];
		$dates = [];
		foreach ($item['fieldValues']['nodes'] ?? [] as $field) {
			if (($field['__typename'] ?? '') === 'ProjectV2ItemFieldDateValue') {
				$dates[(string)($field['field']['id'] ?? '')] = (string)($field['date'] ?? '');
			}
		}
		ksort($dates);
		return hash('sha256', implode('|', [
			$c['title'] ?? '', $c['body'] ?? '', GithubProjectService::statusOf($item), (string)(int)($c['closed'] ?? false),
			json_encode($dates),
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

	/**
	 * Resolve the field ID to use: stored value wins, detected value fills
	 * gaps. If the stored ID no longer exists (field deleted and recreated),
	 * adopt the detected one – but never clobber a deliberate override of a
	 * still-existing field. Persists changes on the map.
	 */
	private function refreshFieldId(BoardMap $map, string $which, string $stored, string $detected, array $knownIds): string {
		if ($stored === '' && $detected !== '') {
			$this->setMapFieldId($map, $which, $detected);
			return $detected;
		}
		if ($stored !== '' && $detected !== '' && $stored !== $detected && !isset($knownIds[$stored])) {
			$this->logger->info('deckgithubsync: adopting recreated field ID', ['field' => $which]);
			$this->setMapFieldId($map, $which, $detected);
			if ($this->hasEstablishedLinks) {
				$this->schemaChanged = true;
			}
			return $detected;
		}
		return $stored !== '' ? $stored : $detected;
	}

	private function setMapFieldId(BoardMap $map, string $which, string $id): void {
		match ($which) {
			'status' => $map->setStatusFieldId($id),
			'due' => $map->setDateFieldId($id),
			'start' => $map->setStartFieldId($id),
			default => throw new \InvalidArgumentException('Unknown field ' . $which),
		};
	}

	/** @return array{gh2deck: array<string,string>, deck2gh: array<string,string>} */
	private function loadUserMap(int $mapId): array {		$gh2deck = [];
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
