<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Maps a GitHub login to a Deck/Nextcloud user id, per board map.
 *
 * @method int getMapId()
 * @method void setMapId(int $v)
 * @method string getGithubLogin()
 * @method void setGithubLogin(string $v)
 * @method string getDeckUid()
 * @method void setDeckUid(string $v)
 */
class UserMap extends Entity {
	protected $mapId = 0;
	protected $githubLogin = '';
	protected $deckUid = '';

	public function __construct() {
		$this->addType('mapId', 'integer');
		$this->addType('githubLogin', 'string');
		$this->addType('deckUid', 'string');
	}
}
