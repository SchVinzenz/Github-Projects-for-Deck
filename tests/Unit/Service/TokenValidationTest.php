<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Service\GithubClientService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TokenValidationTest extends TestCase {
	public function testOAuthExchangeUsesFormEncodedCallback(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"access_token":"oauth-token","refresh_token":"refresh-token","expires_in":28800}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->assertSame('https://github.com/login/oauth/access_token', $url);
				$this->assertSame('application/x-www-form-urlencoded', $options['headers']['Content-Type']);
				parse_str($options['body'], $body);
				$this->assertSame('https://cloud.example/apps/deckgithubsync/oauth/callback', $body['redirect_uri']);
				$this->assertSame('one-time-code', $body['code']);
				return $response;
			}
		);
		$data = $this->service($client)->exchangeOAuthCode(
			'client-id', 'client-secret', 'one-time-code',
			'https://cloud.example/apps/deckgithubsync/oauth/callback',
		);
		$this->assertSame('oauth-token', $data['access_token']);
	}

	public function testExpiredOAuthTokenIsRefreshed(): void {
		$values = [
			'github_token' => 'old-token',
			'github_refresh_token' => 'old-refresh',
			'github_token_expires_at' => (string)(time() - 10),
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function ($uid, $app, $key, $default = '') use (&$values) {
				return $values[$key] ?? $default;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function ($uid, $app, $key, $value) use (&$values): void {
				$values[$key] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			static function ($uid, $app, $key) use (&$values): void {
				unset($values[$key]);
			}
		);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default = '') => [
				'oauth_client_id' => 'client-id',
				'oauth_client_secret' => 'client-secret',
			][$key] ?? $default
		);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"access_token":"new-token","refresh_token":"new-refresh","expires_in":28800}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				parse_str($options['body'], $body);
				$this->assertSame('refresh_token', $body['grant_type']);
				$this->assertSame('old-refresh', $body['refresh_token']);
				return $response;
			}
		);
		$service = $this->service($client, $config);
		$this->assertSame('new-token', $service->getUserAccessToken('alice'));
		$this->assertSame('new-refresh', $values['github_refresh_token']);
		$this->assertGreaterThan(time(), (int)$values['github_token_expires_at']);
	}

	public function testPersonalTokenTakesPriorityOverInstallation(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn ($uid, $app, $key, $default = '') => $key === 'github_token' ? 'personal-token' : $default
		);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default = '') => $key === 'github_installation_id' ? '123' : $default
		);
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$this->assertSame('personal-token', $this->service($client, $config)->resolveToken('alice'));
	}

	public function testValidTokenReturnsLogin(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"login":"alice"}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($response);
		$service = $this->service($client);
		$this->assertSame(['login' => 'alice', 'error' => null, 'status' => 200], $service->inspectUserToken('secret'));
	}

	public function testUnauthorizedTokenIsDistinguishedFromConnectionFailure(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(401);
		$error = new class($response) extends \RuntimeException {
			public function __construct(private IResponse $response) {
				parent::__construct('Rejected');
			}
			public function getResponse(): IResponse {
				return $this->response;
			}
		};
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException($error);
		$result = $this->service($client)->inspectUserToken('secret');
		$this->assertNull($result['login']);
		$this->assertSame(401, $result['status']);
		$this->assertStringContainsString('abgelehnt', $result['error']);
	}

	private function service(IClient $client, ?IConfig $config = null): GithubClientService {
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		return new GithubClientService($clients, $config ?? $this->createMock(IConfig::class), $this->createMock(LoggerInterface::class));
	}
}
