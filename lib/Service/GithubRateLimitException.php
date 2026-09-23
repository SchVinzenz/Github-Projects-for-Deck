<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

class GithubRateLimitException extends \RuntimeException {
	public function __construct(private int $retryAt) {
		parent::__construct('GitHub rate limit reached; retry after ' . gmdate('c', $retryAt));
	}

	public function getRetryAt(): int {
		return $this->retryAt;
	}
}
