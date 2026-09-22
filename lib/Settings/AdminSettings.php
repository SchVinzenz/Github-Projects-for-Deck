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
use OCP\Settings\IDelegatedSettings;

class AdminSettings implements IDelegatedSettings {
	public function __construct(private IInitialState $initialState) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return 'connected-accounts';
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
