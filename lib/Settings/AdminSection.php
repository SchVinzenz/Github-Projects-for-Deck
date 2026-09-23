<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Settings;

use OCA\DeckGithubSync\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Deck-GitHub Sync');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app.svg');
	}
}
