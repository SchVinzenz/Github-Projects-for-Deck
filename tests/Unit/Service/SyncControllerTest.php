<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Controller\SyncController;
use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\IRequest;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SyncControllerTest extends TestCase {
	public function testConcurrentSyncReturnsAcceptedInsteadOfServerError(): void {
		$map = new BoardMap();
		$map->setId(4);
		$sync = $this->createMock(SyncService::class);
		$sync->method('findMap')->with(4, 'alice')->willReturn($map);
		$sync->method('syncBoard')->with($map)->willThrowException(new LockedException('deckgithubsync:map:4'));
		$controller = new SyncController('deckgithubsync', $this->createMock(IRequest::class), $sync, 'alice', $this->createMock(LoggerInterface::class));
		$response = $controller->trigger(4);
		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['busy' => true], $response->getData());
	}
}
