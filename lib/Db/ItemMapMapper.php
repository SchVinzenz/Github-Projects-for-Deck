<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ItemMap>
 */
class ItemMapMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'deckghs_itemmap', ItemMap::class);
	}

	/** @return ItemMap[] */
	public function findByMap(int $mapId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('map_id', $qb->createNamedParameter($mapId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function findByDeckCard(int $mapId, int $cardId): ?ItemMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('map_id', $qb->createNamedParameter($mapId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deck_card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByGithubItem(int $mapId, string $itemId): ?ItemMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('map_id', $qb->createNamedParameter($mapId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('github_item_id', $qb->createNamedParameter($itemId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return ItemMap[] Links for an Issue node across all mapped projects. */
	public function findByGithubContent(string $contentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('github_content_id', $qb->createNamedParameter($contentId)));
		return $this->findEntities($qb);
	}
}
