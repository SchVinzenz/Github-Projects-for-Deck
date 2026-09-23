<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Db\ItemMap;
use OCA\DeckGithubSync\Db\ItemMapMapper;
use OCA\DeckGithubSync\Db\UserMapMapper;
use OCA\DeckGithubSync\Service\DeckService;
use OCA\DeckGithubSync\Service\GithubProjectService;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SyncReliabilityTest extends TestCase {
	public function testGithubIssueCreatesDeckListCardCommentAndDoneState(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$map->setDirection(BoardMap::DIR_TO_DECK);
		$map->setFieldConfig('{}');
		$map->setStatusFieldId('F1');
		(new \ReflectionProperty(BoardMap::class, 'dateFieldId'))->setValue($map, null);
		$boardMaps = $this->createMock(BoardMapMapper::class);
		$itemMaps = $this->createMock(ItemMapMapper::class);
		$itemMaps->method('findByMap')->willReturn([]);
		$itemMaps->method('findByDeckCard')->willReturn(null);
		$itemMaps->method('findByGithubItem')->willReturn(null);
		$itemMaps->expects($this->once())->method('insert');
		$userMaps = $this->createMock(UserMapMapper::class);
		$userMaps->method('findByMap')->willReturn([]);
		$deck = $this->createMock(DeckService::class);
		$deck->method('getCards')->willReturn([]);
		$deck->method('getStacks')->willReturn([['id' => 30, 'title' => 'To do']]);
		$deck->expects($this->once())->method('createStack')->with('alice', 10, 'In Progress')->willReturn(31);
		$deck->expects($this->once())->method('createCard')->with('alice', 31, 'GitHub issue', 'Description', null, null)->willReturn(20);
		$deck->expects($this->once())->method('setDone')->with('alice', 20, true);
		$deck->expects($this->once())->method('syncLabels')->with('alice', 10, 20, ['bug'], true);
		$deck->method('getComments')->willReturn([]);
		$deck->expects($this->once())->method('addComment')->with('alice', 20, '[GitHub bob] Hello');
		$github = $this->createMock(GithubProjectService::class);
		$github->method('getFields')->willReturn([
			'statusFieldId' => 'F1', 'dateFieldId' => '', 'startDateFieldId' => '',
			'options' => ['To do' => 'O1', 'In Progress' => 'O2'],
		]);
		$github->method('listItems')->willReturn(['items' => [[
			'id' => 'I1', 'updatedAt' => '2026-09-22T12:00:00Z',
			'content' => ['__typename' => 'Issue', 'id' => 'ISSUE1', 'number' => 7,
				'title' => 'GitHub issue', 'body' => 'Description', 'closed' => true,
				'repository' => ['nameWithOwner' => 'org/repo'],
				'labels' => ['nodes' => [['name' => 'bug']]], 'assignees' => ['nodes' => []]],
			'fieldValues' => ['nodes' => [[
				'__typename' => 'ProjectV2ItemFieldSingleSelectValue', 'name' => 'In Progress',
				'field' => ['id' => 'F1', 'name' => 'Status'],
			]]],
		]], 'hasNext' => false, 'cursor' => null]);
		$github->method('getIssueComments')->willReturn([['id' => 99, 'body' => 'Hello', 'user' => 'bob', 'created_at' => '']]);
		$sync = new SyncService($boardMaps, $itemMaps, $userMaps, $deck, $github,
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$result = $sync->syncBoard($map);
		$this->assertSame([], $result['errors']);
		$this->assertSame(1, $result['github_to_deck']);
	}

	public function testNewDeckCardBecomesIssueWithLabelsStatusAndClosedState(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$map->setGithubRepository('org/repo');
		$map->setDirection(BoardMap::DIR_TO_GITHUB);
		$map->setFieldConfig('{}');
		$map->setStatusFieldId('F1');
		$boardMaps = $this->createMock(BoardMapMapper::class);
		$itemMaps = $this->createMock(ItemMapMapper::class);
		$itemMaps->method('findByMap')->willReturn([]);
		$itemMaps->method('findByDeckCard')->willReturn(null);
		$itemMaps->method('findByGithubItem')->willReturn(null);
		$itemMaps->expects($this->once())->method('insert');
		$userMaps = $this->createMock(UserMapMapper::class);
		$userMaps->method('findByMap')->willReturn([]);
		$deck = $this->createMock(DeckService::class);
		$deck->method('getCards')->willReturn([[
			'id' => 20, 'title' => 'A card', 'description' => 'Body', 'stackId' => 30,
			'duedate' => null, 'startdate' => null, 'done' => true,
			'labels' => ['bug'], 'assignedUsers' => [], 'lastModified' => 1,
		]]);
		$deck->method('getStacks')->willReturn([['id' => 30, 'title' => 'To do']]);
		$github = $this->createMock(GithubProjectService::class);
		$github->method('getFields')->willReturn(['statusFieldId' => 'F1', 'dateFieldId' => '', 'startDateFieldId' => '', 'options' => ['To do' => 'O1']]);
		$github->method('ensureStatusOptions')->willReturn(['to do' => 'O1']);
		$github->method('ensureDateFields')->willReturn(['startDateFieldId' => 'S1', 'dateFieldId' => 'D1']);
		$github->method('listItems')->willReturn(['items' => [], 'hasNext' => false, 'cursor' => null]);
		$github->method('addDraft')->willReturn('I1');
		$github->expects($this->once())->method('convertDraftToIssue')->with('alice', 'P1', 'I1', 'org/repo')->willReturn([
			'id' => 'I1', 'content' => [
				'__typename' => 'Issue', 'id' => 'ISSUE1', 'number' => 7,
				'title' => 'A card', 'body' => 'Body', 'closed' => false,
				'repository' => ['nameWithOwner' => 'org/repo'], 'labels' => ['nodes' => []],
			], 'fieldValues' => ['nodes' => []],
		]);
		$github->expects($this->once())->method('updateIssueRest')->with('alice', 'org/repo', 7, ['state' => 'closed']);
		$github->expects($this->once())->method('setIssueLabels')->with('alice', 'org/repo', 7, ['bug']);
		$github->expects($this->once())->method('setStatus')->with('alice', 'P1', 'I1', 'F1', 'O1');
		$sync = new SyncService($boardMaps, $itemMaps, $userMaps, $deck, $github,
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$result = $sync->syncBoard($map);
		$this->assertSame([], $result['errors']);
		$this->assertSame(1, $result['deck_to_github']);
	}

	public function testPendingDraftRetriesStatusWithoutCreatingAnotherDraft(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$map->setDirection(BoardMap::DIR_BOTH);
		$map->setFieldConfig('{}');
		$map->setStatusFieldId('F1');
		(new \ReflectionProperty(BoardMap::class, 'dateFieldId'))->setValue($map, null);
		$link = new ItemMap();
		$link->setMapId(1);
		$link->setDeckCardId(20);
		$link->setGithubItemId('I1');
		$link->setSyncHash('pending_draft');
		$link->setDeckHash('');
		$link->setGithubHash('');
		$boardMaps = $this->createMock(BoardMapMapper::class);
		$itemMaps = $this->createMock(ItemMapMapper::class);
		$itemMaps->method('findByMap')->willReturn([$link]);
		$itemMaps->expects($this->once())->method('update')->with($link);
		$userMaps = $this->createMock(UserMapMapper::class);
		$userMaps->method('findByMap')->willReturn([]);
		$deck = $this->createMock(DeckService::class);
		$deck->method('getCards')->willReturn([[
			'id' => 20, 'title' => 'A card', 'description' => 'Body', 'stackId' => 30,
			'duedate' => null, 'labels' => [], 'assignedUsers' => [], 'lastModified' => 1,
		]]);
		$deck->method('getStacks')->willReturn([['id' => 30, 'title' => 'To do']]);
		$github = $this->createMock(GithubProjectService::class);
		$github->method('getFields')->willReturn(['statusFieldId' => 'F1', 'dateFieldId' => '', 'options' => ['To do' => 'O1']]);
		$github->method('ensureStatusOptions')->willReturn(['to do' => 'O1']);
		$github->method('ensureDateFields')->willReturn(['startDateFieldId' => 'S1', 'dateFieldId' => 'D1']);
		$github->method('listItems')->willReturn(['items' => [[
			'id' => 'I1', 'updatedAt' => '2026-09-22T12:00:00Z',
			'content' => ['__typename' => 'DraftIssue', 'id' => 'D1', 'title' => 'A card', 'body' => 'Body'],
			'fieldValues' => ['nodes' => []],
		]], 'hasNext' => false, 'cursor' => null]);
		$github->expects($this->never())->method('addDraft');
		$github->expects($this->once())->method('setStatus')->with('alice', 'P1', 'I1', 'F1', 'O1');
		$sync = new SyncService($boardMaps, $itemMaps, $userMaps, $deck, $github,
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$result = $sync->syncBoard($map);
		$this->assertSame([], $result['errors']);
		$this->assertSame(1, $result['deck_to_github']);
		$this->assertSame('', $link->getSyncHash());
	}

	public function testNewDraftIsLinkedBeforeOptionalStatusUpdateFails(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$map->setDirection(BoardMap::DIR_TO_GITHUB);
		$map->setFieldConfig('{}');
		$map->setStatusFieldId('F1');
		$map->setDateFieldId('');

		$boardMaps = $this->createMock(BoardMapMapper::class);
		$itemMaps = $this->createMock(ItemMapMapper::class);
		$itemMaps->method('findByMap')->willReturn([]);
		$itemMaps->method('findByDeckCard')->willReturn(null);
		$itemMaps->method('findByGithubItem')->willReturn(null);
		$linked = null;
		$itemMaps->expects($this->once())->method('insert')->willReturnCallback(static function (ItemMap $item) use (&$linked): ItemMap {
			$linked = $item;
			return $item;
		});
		$itemMaps->expects($this->never())->method('update');
		$userMaps = $this->createMock(UserMapMapper::class);
		$userMaps->method('findByMap')->willReturn([]);

		$deck = $this->createMock(DeckService::class);
		$deck->method('getCards')->willReturn([[
			'id' => 20, 'title' => 'A card', 'description' => 'Body', 'stackId' => 30,
			'duedate' => null, 'labels' => [], 'assignedUsers' => [], 'lastModified' => 1,
		]]);
		$deck->method('getStacks')->willReturn([['id' => 30, 'title' => 'To do']]);

		$github = $this->createMock(GithubProjectService::class);
		$github->method('getFields')->willReturn(['statusFieldId' => 'F1', 'dateFieldId' => '', 'options' => ['To do' => 'O1']]);
		$github->method('ensureStatusOptions')->willReturn(['to do' => 'O1']);
		$github->method('ensureDateFields')->willReturn(['startDateFieldId' => 'S1', 'dateFieldId' => 'D1']);
		$github->method('listItems')->willReturn(['items' => [], 'hasNext' => false, 'cursor' => null]);
		$github->method('addDraft')->willReturn('I1');
		$github->expects($this->once())->method('setStatus')->willReturnCallback(static function () use (&$linked): void {
			self::assertInstanceOf(ItemMap::class, $linked);
			self::assertSame('I1', $linked->getGithubItemId());
			self::assertSame('pending_draft', $linked->getSyncHash());
			throw new \RuntimeException('status update failed');
		});

		$sync = new SyncService($boardMaps, $itemMaps, $userMaps, $deck, $github,
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$result = $sync->syncBoard($map);
		$this->assertCount(1, $result['errors']);
		$this->assertSame(0, $result['deck_to_github']);
	}
}
