<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getMapId()
 * @method void setMapId(int $v)
 * @method int getDeckCardId()
 * @method void setDeckCardId(int $v)
 * @method string getGithubItemId()
 * @method void setGithubItemId(string $v)
 * @method string getGithubContentId()
 * @method void setGithubContentId(string $v)
 * @method string getContentType()
 * @method void setContentType(string $v)
 * @method string getSyncHash()
 * @method void setSyncHash(string $v)
 */
class ItemMap extends Entity {
	protected $mapId = 0;
	protected $deckCardId = 0;
	protected $githubItemId = '';
	protected $githubContentId = '';
	protected $contentType = 'DraftIssue';
	protected $syncHash = '';

	public function __construct() {
		$this->addType('mapId', 'integer');
		$this->addType('deckCardId', 'integer');
		$this->addType('githubItemId', 'string');
		$this->addType('githubContentId', 'string');
		$this->addType('contentType', 'string');
		$this->addType('syncHash', 'string');
	}
}
