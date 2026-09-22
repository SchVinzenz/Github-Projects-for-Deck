<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Controller;

use OCA\DeckGithubSync\AppInfo\Application;
use OCA\DeckGithubSync\Service\GithubClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * GitHub OAuth (user login) flow.
 *
 * Requires a GitHub OAuth App configured by the admin (client id + secret).
 * Scopes: read:user, repo, project, read:org.
 */
class OAuthController extends Controller {
	private const SCOPES = 'read:user repo project read:org';
	private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ISession $session,
		private ISecureRandom $random,
		private IURLGenerator $urls,
		private GithubClientService $github,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function start(): RedirectResponse {
		$clientId = $this->config->getAppValue(Application::APP_ID, 'oauth_client_id', '');
		if ($clientId === '') {
			return new RedirectResponse($this->urls->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID]) . '?gh_error=no_oauth_app');
		}
		$state = $this->random->generate(32);
		$this->session->set('deckgithubsync_oauth_state', $state);
		$this->session->set('deckgithubsync_oauth_user', $this->userId ?? '');
		$url = self::AUTHORIZE_URL . '?' . http_build_query([
			'client_id' => $clientId,
			'scope' => self::SCOPES,
			'state' => $state,
		]);
		return new RedirectResponse($url);
	}

	#[NoAdminRequired]
	public function callback(string $code = '', string $state = '', string $error = ''): RedirectResponse {
		$back = $this->urls->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID]);
		if ($error !== '') {
			return new RedirectResponse($back . '?gh_error=' . urlencode($error));
		}
		$expected = $this->session->get('deckgithubsync_oauth_state');
		$expectedUser = $this->session->get('deckgithubsync_oauth_user');
		$this->session->remove('deckgithubsync_oauth_state');
		$this->session->remove('deckgithubsync_oauth_user');
		if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state)
			|| $expectedUser !== ($this->userId ?? '') || $code === '') {
			return new RedirectResponse($back . '?gh_error=invalid_state');
		}
		$clientId = $this->config->getAppValue(Application::APP_ID, 'oauth_client_id', '');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'oauth_client_secret', '');
		try {
			$token = $this->github->exchangeOAuthCode($clientId, $clientSecret, $code);
			$login = $this->github->getTokenLogin($token);
			if ($login === null) {
				throw new \RuntimeException('Token validation failed');
			}
			$this->github->setUserToken($this->userId ?? '', $token);
			$this->github->setStoredLogin($this->userId ?? '', $login);
		} catch (\Throwable $e) {
			$this->logger->warning('deckgithubsync: OAuth callback failed', ['exception' => $e]);
			return new RedirectResponse($back . '?gh_error=exchange_failed');
		}
		return new RedirectResponse($back . '?gh_connected=1');
	}
}
