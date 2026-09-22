<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Handles GitHub App JWT + installation tokens and GraphQL calls.
 */
class GithubClientService {
	public const GRAPHQL_URL = 'https://api.github.com/graphql';
	public const API_BASE = 'https://api.github.com';

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getAppId(): string {
		return $this->config->getAppValue('deckgithubsync', 'github_app_id', '');
	}

	public function getInstallationId(string $userId): string {
		// per-user installation override, fallback to global
		$perUser = $this->config->getUserValue($userId, 'deckgithubsync', 'installation_id', '');
		if ($perUser !== '') {
			return $perUser;
		}
		return $this->config->getAppValue('deckgithubsync', 'github_installation_id', '');
	}

	public function getUserToken(string $userId): string {
		return $this->config->getUserValue($userId, 'deckgithubsync', 'github_token', '');
	}

	public function setUserToken(string $userId, string $token): void {
		$this->config->setUserValue($userId, 'deckgithubsync', 'github_token', $token);
	}

	public function clearUserToken(string $userId): void {
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_token');
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_login');
	}

	public function getStoredLogin(string $userId): string {
		return $this->config->getUserValue($userId, 'deckgithubsync', 'github_login', '');
	}

	public function setStoredLogin(string $userId, string $login): void {
		$this->config->setUserValue($userId, 'deckgithubsync', 'github_login', $login);
	}

	/** Exchange an OAuth authorize code for a user access token. */
	public function exchangeOAuthCode(string $clientId, string $clientSecret, string $code): string {
		$client = $this->clientService->newClient();
		$resp = $client->post('https://github.com/login/oauth/access_token', [
			'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Nextcloud-deckgithubsync'],
			'body' => json_encode([
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'code' => $code,
			]),
			'timeout' => 20,
		]);
		$data = json_decode($resp->getBody(), true);
		if (!is_array($data) || empty($data['access_token'])) {
			throw new \RuntimeException('GitHub OAuth exchange failed: ' . ($data['error_description'] ?? $data['error'] ?? 'unknown'));
		}
		return $data['access_token'];
	}

	/** Validate a raw token and return the GitHub login, or null. */
	public function getTokenLogin(string $token): ?string {
		$client = $this->clientService->newClient();
		try {
			$resp = $client->get(self::API_BASE . '/user', [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'Nextcloud-deckgithubsync',
				],
				'timeout' => 20,
			]);
			$data = json_decode($resp->getBody(), true);
			return is_array($data) && isset($data['login']) ? (string)$data['login'] : null;
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: token validation failed', ['exception' => $e]);
			return null;
		}
	}

	private function base64Url(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/** Create RS256 JWT for GitHub App auth (10 min validity). */
	public function createAppJwt(): string {
		$appId = $this->getAppId();
		$key = $this->config->getAppValue('deckgithubsync', 'github_private_key', '');
		if ($appId === '' || $key === '') {
			throw new \RuntimeException('GitHub App not configured');
		}
		$header = $this->base64Url((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$now = time();
		$payload = $this->base64Url((string)json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId]));
		$pkey = openssl_pkey_get_private($key);
		if ($pkey === false) {
			throw new \RuntimeException('Invalid GitHub App private key');
		}
		$sig = '';
		if (!openssl_sign("$header.$payload", $sig, $pkey, OPENSSL_ALGO_SHA256)) {
			throw new \RuntimeException('JWT signing failed');
		}
		return "$header.$payload." . $this->base64Url($sig);
	}

	/** Fetch (and cache) an installation access token. */
	public function getInstallationToken(string $userId): string {
		$cacheKey = 'install_token_' . md5($userId . $this->getInstallationId($userId));
		$cached = $this->config->getAppValue('deckgithubsync', $cacheKey, '');
		$exp = (int)$this->config->getAppValue('deckgithubsync', $cacheKey . '_exp', '0');
		if ($cached !== '' && $exp > time() + 120) {
			return $cached;
		}
		$installationId = $this->getInstallationId($userId);
		if ($installationId === '') {
			// Fall back to user PAT (allows dev without GitHub App)
			$pat = $this->getUserToken($userId);
			if ($pat === '') {
				throw new \RuntimeException('No GitHub installation or user token configured');
			}
			return $pat;
		}
		$client = $this->clientService->newClient();
		$resp = $client->post(
			self::API_BASE . '/app/installations/' . urlencode($installationId) . '/access_tokens',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $this->createAppJwt(),
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'Nextcloud-deckgithubsync',
				],
			]
		);
		$data = json_decode($resp->getBody(), true);
		if (!isset($data['token'])) {
			throw new \RuntimeException('Could not obtain installation token');
		}
		$this->config->setAppValue('deckgithubsync', $cacheKey, $data['token']);
		$this->config->setAppValue('deckgithubsync', $cacheKey . '_exp', (string)(strtotime($data['expires_at'] ?? '+55 minutes')));
		return $data['token'];
	}

	public function resolveToken(string $userId): string {
		try {
			$token = $this->getInstallationToken($userId);
		} catch (\Throwable $e) {
			$this->logger->debug('deckgithubsync: installation token failed, trying PAT', ['exception' => $e]);
			$token = $this->getUserToken($userId);
		}
		if ($token === '') {
			throw new \RuntimeException('No GitHub token configured (installation or personal token required)');
		}
		return $token;
	}

	/** Generic REST call, returns decoded JSON. @return array<string,mixed> */
	public function rest(string $userId, string $method, string $path, array $payload = []): array {
		$client = $this->clientService->newClient();
		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->resolveToken($userId),
				'Accept' => 'application/vnd.github+json',
				'Content-Type' => 'application/json',
				'User-Agent' => 'Nextcloud-deckgithubsync',
				'X-GitHub-Api-Version' => '2022-11-28',
			],
			'timeout' => 20,
		];
		if ($payload !== []) {
			$options['body'] = json_encode($payload);
		}
		$url = self::API_BASE . $path;
		$resp = match (strtoupper($method)) {
			'GET' => $client->get($url, $options),
			'POST' => $client->post($url, $options),
			'PATCH' => $client->patch($url, $options),
			'PUT' => $client->put($url, $options),
			'DELETE' => $client->delete($url, $options),
			default => throw new \InvalidArgumentException('Unsupported method ' . $method),
		};
		$decoded = json_decode($resp->getBody(), true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return array{data?: array, errors?: array} */
	public function graphql(string $userId, string $query, array $variables = [], bool $asUser = false): array {
		$client = $this->clientService->newClient();
		$userToken = $asUser ? $this->getUserToken($userId) : '';
		if ($asUser && $userToken === '') {
			throw new \RuntimeException('Connect a GitHub user account to list projects');
		}
		$resp = $client->post(self::GRAPHQL_URL, [
			'headers' => [
				'Authorization' => 'Bearer ' . ($asUser ? $userToken : $this->resolveToken($userId)),
				'Content-Type' => 'application/json',
				'User-Agent' => 'Nextcloud-deckgithubsync',
			],
			'body' => json_encode(['query' => $query, 'variables' => $variables]),
			'timeout' => 20,
		]);
		$decoded = json_decode($resp->getBody(), true);
		if (!is_array($decoded)) {
			throw new \RuntimeException('Invalid GraphQL response');
		}
		if (!empty($decoded['errors'])) {
			$this->logger->warning('deckgithubsync GraphQL errors', ['errors' => $decoded['errors']]);
			throw new \RuntimeException('GitHub GraphQL request failed: ' . substr((string)json_encode($decoded['errors']), 0, 300));
		}
		return $decoded;
	}
}
