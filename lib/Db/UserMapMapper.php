<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<UserMap>
 */
class UserMapMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'deckghs_usermap', UserMap::class);
	}

	/** @return UserMap[] */
	public function findByMap(int $mapId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('map_id', $qb->createNamedParameter($mapId)));
		return $this->findEntities($qb);
	}
}
