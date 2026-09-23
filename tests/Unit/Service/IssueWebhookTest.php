<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Controller\WebhookController;
use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Db\ItemMap;
use OCA\DeckGithubSync\Db\ItemMapMapper;
use OCA\DeckGithubSync\Service\DeckService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IssueWebhookTest extends TestCase {
	public function testIssueQueuesEachMappedProjectOnce(): void {
		$link1 = new ItemMap();
		$link1->setMapId(1);
		$link2 = new ItemMap();
		$link2->setMapId(1);
		$items = $this->createMock(ItemMapMapper::class);
		$items->expects($this->once())->method('findByGithubContent')->with('ISSUE1')->willReturn([$link1, $link2]);
		$map = new BoardMap();
		$map->setId(1);
		$map->setLastSync(123);
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->expects($this->once())->method('findById')->with(1)->willReturn($map);
		$maps->expects($this->once())->method('update')->with($map);
		$controller = new WebhookController('deckgithubsync', $this->createMock(IRequest::class), $this->createMock(IConfig::class),
			$maps, $items, $this->createMock(DeckService::class), $this->createMock(LoggerInterface::class));
		$this->assertSame(1, (new \ReflectionMethod($controller, 'queueIssue'))->invoke($controller, 'ISSUE1'));
		$this->assertSame(0, $map->getLastSync());
	}
}
