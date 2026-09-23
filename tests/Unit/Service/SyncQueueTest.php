<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\BackgroundJob\EventSyncJob;
use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Service\SyncQueueService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

class SyncQueueTest extends TestCase {
	public function testDeckChangeQueuesOnlyMappingsThatPushToGithub(): void {
		$toGithub = new BoardMap();
		$toGithub->setId(1);
		$toGithub->setLastSync(123);
		$toGithub->setDirection(BoardMap::DIR_BOTH);
		$fromGithub = new BoardMap();
		$fromGithub->setId(2);
		$fromGithub->setDirection(BoardMap::DIR_TO_DECK);
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->method('findByDeckBoard')->with(10)->willReturn([$toGithub, $fromGithub]);
		$maps->expects($this->once())->method('update')->with($toGithub);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')->with(
			EventSyncJob::class,
			$this->callback(static fn (int $when): bool => abs($when - time() - 5) <= 1),
			1,
		);
		(new SyncQueueService($maps, $jobs))->queueBoard(10);
		$this->assertSame(0, $toGithub->getLastSync());
	}

	public function testRateLimitCooldownDoesNotQueueJob(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setLastSync(123);
		$map->setCooldownUntil(time() + 60);
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->expects($this->never())->method('update');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('scheduleAfter');
		(new SyncQueueService($maps, $jobs))->queueMap($map);
		$this->assertSame(123, $map->getLastSync());
	}
}
