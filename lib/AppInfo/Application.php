<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\AppInfo;

use OCA\DeckGithubSync\Settings\AdminSettings;
use OCA\DeckGithubSync\Settings\PersonalSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'deckgithubsync';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSettings(AdminSettings::class);
		$context->registerSettings(PersonalSettings::class);
	}

	public function boot(IBootContext $context): void {
	}
}
