<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Controller\SettingsController;
use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Db\ItemMap;
use OCA\DeckGithubSync\Db\ItemMapMapper;
use OCA\DeckGithubSync\Db\UserMap;
use OCA\DeckGithubSync\Db\UserMapMapper;
use OCA\DeckGithubSync\Service\DeckService;
use OCA\DeckGithubSync\Service\GithubClientService;
use OCA\DeckGithubSync\Service\GithubProjectService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class MappingDeletionTest extends TestCase {
	public function testDeletionFailureRollsBackMapping(): void {
		$map = new BoardMap();
		$map->setId(5);
		$map->setUserId('alice');
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->method('findById')->willReturn($map);
		$maps->expects($this->never())->method('delete');
		$items = $this->createMock(ItemMapMapper::class);
		$items->method('findByMap')->willThrowException(new \RuntimeException('Database failure'));
		$controller = new SettingsController('deckgithubsync', $this->createMock(IRequest::class),
			$this->createMock(IConfig::class), $db, $maps, $items,
			$this->createMock(UserMapMapper::class), $this->createMock(GithubProjectService::class),
			$this->createMock(GithubClientService::class), $this->createMock(DeckService::class),
			$this->createMock(IURLGenerator::class), $this->createMock(IL10N::class), 'alice');
		$this->assertSame(500, $controller->deleteMapping(5)->getStatus());
	}

	public function testDeletingMappingRemovesLinksInOneTransaction(): void {
		$map = new BoardMap();
		$map->setId(5);
		$map->setUserId('alice');
		$item = new ItemMap();
		$user = new UserMap();
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$db->expects($this->never())->method('rollBack');
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->method('findById')->willReturn($map);
		$maps->expects($this->once())->method('delete')->with($map);
		$items = $this->createMock(ItemMapMapper::class);
		$items->method('findByMap')->willReturn([$item]);
		$items->expects($this->once())->method('delete')->with($item);
		$users = $this->createMock(UserMapMapper::class);
		$users->method('findByMap')->willReturn([$user]);
		$users->expects($this->once())->method('delete')->with($user);
		$controller = new SettingsController('deckgithubsync', $this->createMock(IRequest::class),
			$this->createMock(IConfig::class), $db, $maps, $items, $users,
			$this->createMock(GithubProjectService::class), $this->createMock(GithubClientService::class),
			$this->createMock(DeckService::class), $this->createMock(IURLGenerator::class), $this->createMock(IL10N::class), 'alice');
		$this->assertSame(200, $controller->deleteMapping(5)->getStatus());
	}

	public function testUserMappingReplacementRollsBackOnInsertFailure(): void {
		$map = new BoardMap();
		$map->setId(5);
		$map->setUserId('alice');
		$oldUserMap = new UserMap();
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->method('findById')->willReturn($map);
		$items = $this->createMock(ItemMapMapper::class);
		$users = $this->createMock(UserMapMapper::class);
		$users->method('findByMap')->willReturn([$oldUserMap]);
		$users->expects($this->once())->method('delete')->with($oldUserMap);
		$users->expects($this->once())->method('insert')->willThrowException(new \RuntimeException('Database failure'));
		$controller = new SettingsController('deckgithubsync', $this->createMock(IRequest::class),
			$this->createMock(IConfig::class), $db, $maps, $items, $users,
			$this->createMock(GithubProjectService::class), $this->createMock(GithubClientService::class),
			$this->createMock(DeckService::class), $this->createMock(IURLGenerator::class),
			$this->createMock(IL10N::class), 'alice');

		$response = $controller->setUserMap(5, [['githubLogin' => 'alice-gh', 'deckUid' => 'alice']]);

		$this->assertSame(500, $response->getStatus());
	}
}
