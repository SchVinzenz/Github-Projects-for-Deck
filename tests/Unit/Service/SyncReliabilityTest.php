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
	public function testPendingDraftRetriesStatusWithoutCreatingAnotherDraft(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$map->setDirection(BoardMap::DIR_BOTH);
		$map->setFieldConfig('{}');
		$map->setStatusFieldId('F1');
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
