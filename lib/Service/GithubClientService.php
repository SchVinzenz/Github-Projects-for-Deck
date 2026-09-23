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
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_refresh_token');
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_token_expires_at');
	}

	public function clearUserToken(string $userId): void {
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_token');
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_login');
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_refresh_token');
		$this->config->deleteUserValue($userId, 'deckgithubsync', 'github_token_expires_at');
	}

	public function getStoredLogin(string $userId): string {
		return $this->config->getUserValue($userId, 'deckgithubsync', 'github_login', '');
	}

	public function setStoredLogin(string $userId, string $login): void {
		$this->config->setUserValue($userId, 'deckgithubsync', 'github_login', $login);
	}

	/** Exchange an OAuth authorize code for a user access token. */
	public function exchangeOAuthCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array {
		return $this->requestOAuthToken([
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'code' => $code,
			'redirect_uri' => $redirectUri,
		]);
	}

	private function requestOAuthToken(array $fields): array {
		$client = $this->clientService->newClient();
		$resp = $client->post('https://github.com/login/oauth/access_token', [
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
				'User-Agent' => 'Nextcloud-deckgithubsync',
			],
			'body' => http_build_query($fields),
			'timeout' => 20,
		]);
		$data = json_decode($resp->getBody(), true);
		if (!is_array($data) || empty($data['access_token'])) {
			$error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response';
			throw new \RuntimeException('GitHub OAuth exchange failed: ' . $error);
		}
		return $data;
	}

	public function storeOAuthToken(string $userId, array $data): void {
		$this->setUserToken($userId, (string)$data['access_token']);
		if (!empty($data['refresh_token'])) {
			$this->config->setUserValue($userId, 'deckgithubsync', 'github_refresh_token', (string)$data['refresh_token']);
		}
		if (!empty($data['expires_in'])) {
			$this->config->setUserValue($userId, 'deckgithubsync', 'github_token_expires_at', (string)(time() + max(1, (int)$data['expires_in'])));
		}
	}

	public function getUserAccessToken(string $userId): string {
		$token = $this->getUserToken($userId);
		if ($token === '') {
			return '';
		}
		$expiresAt = (int)$this->config->getUserValue($userId, 'deckgithubsync', 'github_token_expires_at', '0');
		if ($expiresAt === 0 || $expiresAt > time() + 120) {
			return $token;
		}
		$refreshToken = $this->config->getUserValue($userId, 'deckgithubsync', 'github_refresh_token', '');
		$clientId = $this->config->getAppValue('deckgithubsync', 'oauth_client_id', '');
		$clientSecret = $this->config->getAppValue('deckgithubsync', 'oauth_client_secret', '');
		if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
			throw new \RuntimeException('GitHub OAuth session expired; reconnect');
		}
		try {
			$data = $this->requestOAuthToken([
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'grant_type' => 'refresh_token',
				'refresh_token' => $refreshToken,
			]);
			$this->storeOAuthToken($userId, $data);
			return (string)$data['access_token'];
		} catch (\Throwable $e) {
			$freshToken = $this->getUserToken($userId);
			$freshExpiry = (int)$this->config->getUserValue($userId, 'deckgithubsync', 'github_token_expires_at', '0');
			if ($freshToken !== $token && $freshExpiry > time() + 120) {
				return $freshToken;
			}
			throw $e;
		}
	}

	/** Validate a user token without exposing it in errors or logs. */
	public function inspectUserToken(string $token): array {
		try {
			$client = $this->clientService->newClient();
			$resp = $client->get(self::API_BASE . '/user', [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'Nextcloud-deckgithubsync',
				],
				'timeout' => 20,
			]);
			$data = json_decode($resp->getBody(), true);
			if (is_array($data) && isset($data['login']) && is_string($data['login'])) {
				return ['login' => $data['login'], 'error' => null, 'status' => 200];
			}
			return ['login' => null, 'error' => 'GitHub hat keine Benutzerkennung zurückgegeben.', 'status' => 502];
		} catch (\Throwable $e) {
			$status = null;
			if (method_exists($e, 'getResponse')) {
				$response = $e->getResponse();
				$status = $response !== null ? $response->getStatusCode() : null;
			}
			$this->logger->warning('deckgithubsync: GitHub token validation failed', ['githubStatus' => $status, 'exceptionType' => get_class($e)]);
			if ($status === 401) {
				return ['login' => null, 'error' => 'GitHub hat den Token abgelehnt (401). Token und Ablaufdatum prüfen.', 'status' => 401];
			}
			if ($status === 403) {
				return ['login' => null, 'error' => 'GitHub verweigert den Zugriff (403). Berechtigungen oder Rate-Limit prüfen.', 'status' => 403];
			}
			return ['login' => null, 'error' => 'GitHub ist vom Nextcloud-Server aus nicht erreichbar oder antwortet fehlerhaft.', 'status' => 502];
		}
	}

	public function getTokenLogin(string $token): ?string {
		return $this->inspectUserToken($token)['login'];
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
			throw new \RuntimeException('No GitHub installation configured');
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
		if ($this->getUserToken($userId) !== '') {
			try {
				return $this->getUserAccessToken($userId);
			} catch (\Throwable $e) {
				if ($e instanceof GithubRateLimitException) {
					throw $e;
				}
				$this->logger->warning('deckgithubsync: user token refresh failed; trying installation token', ['exceptionType' => get_class($e)]);
				if ($this->getInstallationId($userId) === '') {
					throw $e;
				}
			}
		}
		return $this->getInstallationToken($userId);
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
		try {
			$resp = match (strtoupper($method)) {
			'GET' => $client->get($url, $options),
			'POST' => $client->post($url, $options),
			'PATCH' => $client->patch($url, $options),
			'PUT' => $client->put($url, $options),
			'DELETE' => $client->delete($url, $options),
			default => throw new \InvalidArgumentException('Unsupported method ' . $method),
			};
		} catch (\Throwable $e) {
			$this->throwIfRateLimited($e);
			throw $e;
		}
		$decoded = json_decode($resp->getBody(), true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @return array{data?: array, errors?: array} */
	public function graphql(string $userId, string $query, array $variables = [], bool $asUser = false): array {
		$client = $this->clientService->newClient();
		$userToken = $asUser ? $this->getUserAccessToken($userId) : '';
		if ($asUser && $userToken === '') {
			throw new \RuntimeException('Connect a GitHub user account to list projects');
		}
		try {
			$resp = $client->post(self::GRAPHQL_URL, [
			'headers' => [
				'Authorization' => 'Bearer ' . ($asUser ? $userToken : $this->resolveToken($userId)),
				'Content-Type' => 'application/json',
				'User-Agent' => 'Nextcloud-deckgithubsync',
			],
			'body' => json_encode(['query' => $query, 'variables' => $variables]),
			'timeout' => 20,
			]);
		} catch (\Throwable $e) {
			$this->throwIfRateLimited($e);
			throw $e;
		}
		$decoded = json_decode($resp->getBody(), true);
		if (!is_array($decoded)) {
			throw new \RuntimeException('Invalid GraphQL response');
		}
		if (!empty($decoded['errors'])) {
			foreach ($decoded['errors'] as $error) {
				if (($error['type'] ?? $error['extensions']['code'] ?? '') === 'RATE_LIMITED') {
					throw new GithubRateLimitException($this->retryAt($resp));
				}
			}
			$this->logger->warning('deckgithubsync GraphQL errors', ['errors' => $decoded['errors']]);
			throw new \RuntimeException('GitHub GraphQL request failed: ' . substr((string)json_encode($decoded['errors']), 0, 300));
		}
		return $decoded;
	}

	private function throwIfRateLimited(\Throwable $e): void {
		if (!method_exists($e, 'getResponse') || ($response = $e->getResponse()) === null) {
			return;
		}
		$status = $response->getStatusCode();
		if ($status !== 429 && $status !== 403) {
			return;
		}
		$body = strtolower((string)$response->getBody());
		$remaining = (string)$response->getHeader('X-RateLimit-Remaining');
		if ($status === 429 || $remaining === '0' || str_contains($body, 'rate limit')) {
			throw new GithubRateLimitException($this->retryAt($response));
		}
	}

	private function retryAt(mixed $response): int {
		$now = time();
		$retryAfter = (string)$response->getHeader('Retry-After');
		if (ctype_digit($retryAfter)) {
			return $now + max(60, min(86400, (int)$retryAfter));
		}
		if ($retryAfter !== '' && ($parsed = strtotime($retryAfter)) !== false) {
			return max($now + 60, min($now + 86400, $parsed));
		}
		$reset = (string)$response->getHeader('X-RateLimit-Reset');
		return ctype_digit($reset) ? max($now + 60, min($now + 86400, (int)$reset)) : $now + 300;
	}
}
