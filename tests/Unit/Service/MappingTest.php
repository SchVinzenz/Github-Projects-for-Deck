<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Db\BoardMap;
use OCA\DeckGithubSync\Service\GithubProjectService;
use PHPUnit\Framework\TestCase;

class MappingTest extends TestCase {
	public function testFieldMapDefaults(): void {
		$map = new BoardMap();
		$map->setFieldConfig('{}');
		$this->assertSame('both', $map->getFieldMap()['title']);
		$this->assertSame('both', $map->getFieldMap()['status']);
	}

	public function testFieldMapOverride(): void {
		$map = new BoardMap();
		$map->setFieldConfig('{"title":"deck_to_github","status":"off"}');
		$fm = $map->getFieldMap();
		$this->assertSame('deck_to_github', $fm['title']);
		$this->assertSame('off', $fm['status']);
		$this->assertSame('both', $fm['description']);
	}

	public function testStatusExtraction(): void {
		$item = ['fieldValues' => ['nodes' => [
			['__typename' => 'ProjectV2ItemFieldSingleSelectValue', 'name' => 'In Progress', 'field' => ['name' => 'Status']],
		]]];
		$this->assertSame('In Progress', GithubProjectService::statusOf($item));
		$this->assertSame('', GithubProjectService::statusOf(['fieldValues' => ['nodes' => []]]));
	}

	public function testLabelsAndAssigneesExtraction(): void {
		$item = ['content' => [
			'labels' => ['nodes' => [['name' => 'bug'], ['name' => 'ui']]],
			'assignees' => ['nodes' => [['login' => 'alice']]],
			'repository' => ['nameWithOwner' => 'org/repo'],
			'number' => 42,
		]];
		$this->assertSame(['bug', 'ui'], GithubProjectService::labelsOf($item));
		$this->assertSame(['alice'], GithubProjectService::assigneesOf($item));
		$this->assertSame('org/repo', GithubProjectService::repoOf($item));
		$this->assertSame(42, GithubProjectService::numberOf($item));
	}

	public function testDateExtraction(): void {
		$item = ['fieldValues' => ['nodes' => [
			['__typename' => 'ProjectV2ItemFieldDateValue', 'date' => '2026-10-01', 'field' => ['id' => 'F1']],
			['__typename' => 'ProjectV2ItemFieldSingleSelectValue', 'name' => 'Todo', 'field' => ['name' => 'Status']],
		]]];
		$this->assertSame('2026-10-01', GithubProjectService::dateOf($item, 'F1'));
		$this->assertSame('2026-10-01', GithubProjectService::dateOf($item));
		$this->assertNull(GithubProjectService::dateOf($item, 'OTHER'));
		$this->assertNull(GithubProjectService::dateOf(['fieldValues' => ['nodes' => []]]));
	}
}
