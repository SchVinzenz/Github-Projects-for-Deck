<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Settings;

use OCA\DeckGithubSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

class AdminSettings implements IDelegatedSettings {
	public function __construct(
		private IInitialState $initialState,
		private IConfig $config,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin-config', [
			'github_app_id' => $this->config->getAppValue(Application::APP_ID, 'github_app_id', ''),
			'github_installation_id' => $this->config->getAppValue(Application::APP_ID, 'github_installation_id', ''),
			'has_private_key' => $this->config->getAppValue(Application::APP_ID, 'github_private_key', '') !== '',
			'has_webhook_secret' => $this->config->getAppValue(Application::APP_ID, 'webhook_secret', '') !== '',
			'sync_interval' => (int)$this->config->getAppValue(Application::APP_ID, 'sync_interval', '900'),
		]);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 80;
	}

	public function getName(): ?string {
		return null;
	}

	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
