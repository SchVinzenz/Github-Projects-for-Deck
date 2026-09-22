<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Service\GithubClientService;
use OCA\DeckGithubSync\Service\GithubProjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectDiscoveryTest extends TestCase {
	public function testResolvesPersonalProjectWhenOrganizationLookupWouldFail(): void {
		$client = $this->createMock(GithubClientService::class);
		$client->expects($this->once())->method('graphql')
			->willReturnCallback(static function (string $uid, string $query, array $variables): array {
				self::assertSame('nextcloud-user', $uid);
				self::assertStringContainsString('user(login:', $query);
				self::assertSame(['login' => 'SchVinzenz', 'num' => 3], $variables);
				return ['data' => ['user' => ['projectV2' => ['id' => 'P3']]]];
			});
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$this->assertSame('P3', $service->resolveProjectId('nextcloud-user', 'SchVinzenz', 3));
	}

	public function testResolvesOrganizationProjectAfterUserLookupFails(): void {
		$client = $this->createMock(GithubClientService::class);
		$calls = 0;
		$client->expects($this->exactly(2))->method('graphql')
			->willReturnCallback(static function (string $uid, string $query, array $variables) use (&$calls): array {
				self::assertSame('team', $variables['login']);
				if ($calls++ === 0) {
					self::assertStringContainsString('user(login:', $query);
					throw new \RuntimeException('GitHub GraphQL request failed: user not found');
				}
				self::assertStringContainsString('organization(login:', $query);
				return ['data' => ['organization' => ['projectV2' => ['id' => 'P7']]]];
			});
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$this->assertSame('P7', $service->resolveProjectId('nextcloud-user', 'team', 7));
	}

	public function testListsUserAndOrganizationProjectsAcrossPages(): void {
		$client = $this->createMock(GithubClientService::class);
		$responses = [
			['data' => ['viewer' => ['login' => 'alice', 'organizations' => [
				'nodes' => [['login' => 'team']],
				'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
			]]]],
			['data' => ['user' => ['projectsV2' => [
				'nodes' => [['id' => 'P1', 'number' => 2, 'title' => 'Personal', 'url' => 'https://github.com/users/alice/projects/2']],
				'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'next'],
			]]]],
			['data' => ['user' => ['projectsV2' => [
				'nodes' => [['id' => 'P2', 'number' => 3, 'title' => 'Other']],
				'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
			]]]],
			['data' => ['organization' => ['projectsV2' => [
				'nodes' => [['id' => 'P3', 'number' => 7, 'title' => 'Team']],
				'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
			]]]],
		];
		$calls = 0;
		$client->expects($this->exactly(4))->method('graphql')
			->willReturnCallback(static function (string $uid, string $query, array $variables, bool $asUser) use (&$calls, $responses): array {
				self::assertSame('nextcloud-user', $uid);
				self::assertTrue($asUser);
				if ($calls === 2) {
					self::assertSame('next', $variables['after']);
				}
				return $responses[$calls++];
			});
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$projects = $service->listAvailableProjects('nextcloud-user');
		$this->assertSame(['P2', 'P1', 'P3'], array_column($projects, 'id'));
		$this->assertSame(['alice', 'alice', 'team'], array_column($projects, 'owner'));
		$this->assertSame(7, $projects[2]['number']);
	}

	public function testRejectsIncompleteItemPagination(): void {
		$client = $this->createMock(GithubClientService::class);
		$client->method('graphql')->willReturn(['data' => ['node' => ['items' => [
			'nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null],
		]]]]);
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$this->expectExceptionMessage('GitHub item pagination failed');
		$service->listItems('nextcloud-user', 'P3');
	}

	public function testInaccessibleOrganizationDoesNotHidePersonalProjects(): void {
		$client = $this->createMock(GithubClientService::class);
		$calls = 0;
		$client->method('graphql')->willReturnCallback(static function () use (&$calls): array {
			return match ($calls++) {
				0 => ['data' => ['viewer' => ['login' => 'alice', 'organizations' => [
					'nodes' => [['login' => 'restricted']],
					'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
				]]]],
				1 => ['data' => ['user' => ['projectsV2' => [
					'nodes' => [['id' => 'P1', 'number' => 1, 'title' => 'Personal']],
					'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
				]]]],
				default => throw new \RuntimeException('GitHub GraphQL request failed: organization project access denied'),
			};
		});
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$this->assertSame(['P1'], array_column($service->listAvailableProjects('alice'), 'id'));
	}

	public function testReadsAllIssueCommentPages(): void {
		$client = $this->createMock(GithubClientService::class);
		$client->expects($this->exactly(2))->method('rest')
			->willReturnCallback(static function (string $uid, string $method, string $path): array {
				self::assertSame('GET', $method);
				if (str_ends_with($path, 'page=1')) {
					return array_fill(0, 100, ['id' => 1, 'body' => 'First', 'user' => ['login' => 'alice']]);
				}
				self::assertStringEndsWith('page=2', $path);
				return [['id' => 2, 'body' => 'Last', 'user' => ['login' => 'bob']]];
			});
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$comments = $service->getIssueComments('alice', 'org/repo', 7);
		$this->assertCount(101, $comments);
		$this->assertSame('Last', $comments[100]['body']);
	}

	public function testOrganizationNetworkFailureIsNotHidden(): void {
		$client = $this->createMock(GithubClientService::class);
		$calls = 0;
		$client->method('graphql')->willReturnCallback(static function () use (&$calls): array {
			return match ($calls++) {
				0 => ['data' => ['viewer' => ['login' => 'alice', 'organizations' => [
					'nodes' => [['login' => 'team']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
				]]]],
				1 => ['data' => ['user' => ['projectsV2' => [
					'nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
				]]]],
				default => throw new \RuntimeException('Network timeout'),
			};
		});
		$service = new GithubProjectService($client, $this->createMock(LoggerInterface::class));
		$this->expectExceptionMessage('Network timeout');
		$service->listAvailableProjects('alice');
	}
}
