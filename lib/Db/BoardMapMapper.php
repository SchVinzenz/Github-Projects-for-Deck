<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BoardMap>
 */
class BoardMapMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'deckghs_boardmap', BoardMap::class);
	}

	public function findById(int $id): BoardMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return BoardMap[] */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	/** @return BoardMap[] */
	public function findAllDue(int $olderThan, ?int $now = null): array {
		$now ??= time();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('last_sync', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('cooldown_until'),
				$qb->expr()->lte('cooldown_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			));
		return $this->findEntities($qb);
	}

	/** @return BoardMap[] */
	public function findByProject(string $projectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('github_project_id', $qb->createNamedParameter($projectId)));
		return $this->findEntities($qb);
	}
}
