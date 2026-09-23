<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Db\BoardMapMapper;
use OCA\DeckGithubSync\Db\ItemMapMapper;
use OCA\DeckGithubSync\Db\UserMapMapper;
use OCA\DeckGithubSync\Service\DeckService;
use OCA\DeckGithubSync\Service\GithubClientService;
use OCA\DeckGithubSync\Service\GithubProjectService;
use OCA\DeckGithubSync\Service\GithubRateLimitException;
use OCA\DeckGithubSync\Service\SyncService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RateLimitTest extends TestCase {
	public function testRetryAfterTakesPrecedenceOverReset(): void {
		$client = new GithubClientService($this->createMock(IClientService::class), $this->createMock(IConfig::class), $this->createMock(LoggerInterface::class), $this->createMock(IL10N::class), $this->createMock(ILockingProvider::class));
		$response = new class {
			public function getHeader(string $name): string {
				return match ($name) {
					'Retry-After' => '120',
					'X-RateLimit-Reset' => (string)(time() + 600),
					default => '',
				};
			}
		};
		$retryAt = (new \ReflectionMethod($client, 'retryAt'))->invoke($client, $response);
		$this->assertGreaterThanOrEqual(time() + 119, $retryAt);
		$this->assertLessThanOrEqual(time() + 121, $retryAt);
	}

	public function testRateLimitPutsMappingInCooldown(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setUserId('alice');
		$map->setDeckBoardId(10);
		$map->setGithubProjectId('P1');
		$retryAt = time() + 300;
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->expects($this->once())->method('update')->with($map);
		$deck = $this->createMock(DeckService::class);
		$deck->method('getCards')->willReturn([]);
		$deck->method('getStacks')->willReturn([]);
		$github = $this->createMock(GithubProjectService::class);
		$github->method('getFields')->willThrowException(new GithubRateLimitException($retryAt));
		$sync = new SyncService($maps, $this->createMock(ItemMapMapper::class), $this->createMock(UserMapMapper::class), $deck, $github,
			$this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $this->createMock(IL10N::class), $this->createMock(ILockingProvider::class));
		$result = $sync->syncBoard($map);
		$this->assertSame(0, $map->getLastSync());
		$this->assertSame($retryAt, $map->getCooldownUntil());
		$this->assertSame($retryAt, $result['retryAt']);
	}

	public function testSyncSkipsGithubWhileMappingIsInCooldown(): void {
		$map = new BoardMap();
		$map->setId(1);
		$map->setCooldownUntil(time() + 300);
		$github = $this->createMock(GithubProjectService::class);
		$github->expects($this->never())->method('getFields');
		$maps = $this->createMock(BoardMapMapper::class);
		$maps->expects($this->never())->method('update');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')->with('deckgithubsync:map:' . $map->getId(), ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())->method('releaseLock')->with('deckgithubsync:map:' . $map->getId(), ILockingProvider::LOCK_EXCLUSIVE);
		$sync = new SyncService($maps, $this->createMock(ItemMapMapper::class), $this->createMock(UserMapMapper::class),
			$this->createMock(DeckService::class), $github, $this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $this->createMock(IL10N::class), $locks);

		$result = $sync->syncBoard($map);

		$this->assertSame(0, $result['deck_to_github']);
		$this->assertSame(0, $result['github_to_deck']);
		$this->assertGreaterThan(time(), $result['retryAt']);
	}
}
