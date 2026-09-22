<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method int getDeckBoardId()
 * @method void setDeckBoardId(int $v)
 * @method string getGithubProjectId()
 * @method void setGithubProjectId(string $v)
 * @method string getGithubOwner()
 * @method void setGithubOwner(string $v)
 * @method int getGithubNumber()
 * @method void setGithubNumber(int $v)
 * @method string getGithubRepository()
 * @method void setGithubRepository(string $v)
 * @method string getDirection()
 * @method void setDirection(string $v)
 * @method string getFieldConfig()
 * @method void setFieldConfig(string $v)
 * @method string getStatusFieldId()
 * @method void setStatusFieldId(string $v)
 * @method string getDateFieldId()
 * @method void setDateFieldId(string $v)
 * @method string getStartFieldId()
 * @method void setStartFieldId(string $v)
 * @method int getLastSync()
 * @method void setLastSync(int $v)
 */
class BoardMap extends Entity {
	public const DIR_BOTH = 'both';
	public const DIR_TO_GITHUB = 'deck_to_github';
	public const DIR_TO_DECK = 'github_to_deck';

	protected $userId = '';
	protected $deckBoardId = 0;
	protected $githubProjectId = '';
	protected $githubOwner = '';
	protected $githubNumber = 0;
	protected $githubRepository = '';
	protected $direction = self::DIR_BOTH;
	protected $fieldConfig = '{}';
	protected $statusFieldId = '';
	protected $dateFieldId = '';
	protected $startFieldId = '';
	protected $lastSync = 0;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('deckBoardId', 'integer');
		$this->addType('githubProjectId', 'string');
		$this->addType('githubOwner', 'string');
		$this->addType('githubNumber', 'integer');
		$this->addType('githubRepository', 'string');
		$this->addType('direction', 'string');
		$this->addType('fieldConfig', 'string');
		$this->addType('statusFieldId', 'string');
		$this->addType('dateFieldId', 'string');
		$this->addType('startFieldId', 'string');
		$this->addType('lastSync', 'integer');
	}

	/** Nullable DB columns are normalized to '' so callers can use strict comparisons. */
	public function getGithubRepository(): string {
		return (string)($this->githubRepository ?? '');
	}

	public function getStatusFieldId(): string {
		return (string)($this->statusFieldId ?? '');
	}

	public function getDateFieldId(): string {
		return (string)($this->dateFieldId ?? '');
	}

	public function getStartFieldId(): string {
		return (string)($this->startFieldId ?? '');
	}

	/** @return array<string,string> field => direction */
	public function getFieldMap(): array {
		$defaults = [
			'title' => self::DIR_BOTH,
			'description' => self::DIR_BOTH,
			'status' => self::DIR_BOTH,
			'labels' => self::DIR_BOTH,
			'assignees' => self::DIR_BOTH,
			'due' => self::DIR_BOTH,
			'start' => self::DIR_BOTH,
			'comments' => self::DIR_BOTH,
		];
		try {
			$cfg = json_decode($this->fieldConfig, true, 512, JSON_THROW_ON_ERROR);
			if (is_array($cfg)) {
				return array_merge($defaults, array_intersect_key($cfg, $defaults));
			}
		} catch (\Throwable) {
		}
		return $defaults;
	}
}
