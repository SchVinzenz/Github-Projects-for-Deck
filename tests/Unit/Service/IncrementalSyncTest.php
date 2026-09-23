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
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IncrementalSyncTest extends TestCase {
	public function testNewDeckCommentForcesFullProjectScan(): void {
		$map = new BoardMap();
		$map->setLastSync((int)strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC'));
		$map->setFieldConfig('{}');
		$card = ['id' => 20, 'title' => 'Card', 'stackId' => 30, 'description' => '', 'labels' => [], 'assignedUsers' => []];
		$link = new ItemMap();
		$link->setDeckCardId(20);
		$link->setContentType('Issue');
		$link->setSyncHash('comments:old');
		$deck = $this->createMock(DeckService::class);
		$deck->method('getComments')->with(20)->willReturn(['New comment']);
		$sync = new SyncService($this->createMock(BoardMapMapper::class), $this->createMock(ItemMapMapper::class),
			$this->createMock(UserMapMapper::class), $deck, $this->createMock(GithubProjectService::class),
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $this->createMock(IL10N::class));
		$link->setDeckHash($sync->hashDeck($card));
		$this->assertFalse((new \ReflectionMethod($sync, 'canSyncIncrementally'))->invoke($sync, $map, [$card], ['deck:20' => $link], $map->getFieldMap(), true));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('scanModes')]
	public function testMissingLinkedItemNeverCreatesDuplicate(bool $incremental, ?string $expectedFilter): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$map->setDirection(BoardMap::DIR_TO_GITHUB);
		$map->setLastSync($incremental ? (int)strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC') : 0);
		$card = [
			'id' => 20, 'title' => 'A card', 'description' => 'Body', 'stackId' => 30,
			'duedate' => null, 'startdate' => null, 'done' => false,
			'labels' => [], 'assignedUsers' => [], 'lastModified' => 1,
		];
		$link = new ItemMap();
		$link->setMapId(1);
		$link->setDeckCardId(20);
		$link->setGithubItemId('I1');
		$link->setContentType('DraftIssue');
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->expects($this->once())->method('update');
		$items = $this->createMock(ItemMapMapper::class);
		$items->method('findByMap')->willReturn([$link]);
		$items->expects($this->never())->method('insert');
		$users = $this->createMock(UserMapMapper::class);
		$users->method('findByMap')->willReturn([]);
		$deck = $this->createMock(DeckService::class);
		$deck->method('getCards')->willReturn([$card]);
		$deck->method('getStacks')->willReturn([['id' => 30, 'title' => 'To do']]);
		$github = $this->createMock(GithubProjectService::class);
		$github->method('getFields')->willReturn(['fields' => [], 'options' => [], 'statusFieldId' => '', 'dateFieldId' => '', 'startDateFieldId' => '']);
		$github->method('ensureDateFields')->willReturn(['dateFieldId' => '', 'startDateFieldId' => '']);
		$github->expects($this->once())->method('listItems')->with('alice', 'P1', null, $expectedFilter)
			->willReturn(['items' => [], 'hasNext' => false, 'cursor' => null]);
		$github->expects($this->never())->method('addDraft');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $source): string => $source);
		$sync = new SyncService($maps, $items, $users, $deck, $github,
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $l);
		$link->setDeckHash($sync->hashDeck($card));
		$result = $sync->syncBoard($map);
		$this->assertSame([], $result['errors']);
		$this->assertSame(0, $result['deck_to_github']);
	}

	public static function scanModes(): array {
		return [
			'incremental' => [true, 'updated:>@today-1d'],
			'full' => [false, null],
		];
	}
}
