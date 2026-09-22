<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020000Date20260922000100 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('deckghs_boardmap')) {
			$table = $schema->getTable('deckghs_boardmap');
			if (!$table->hasColumn('date_field_id')) {
				$table->addColumn('date_field_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			}
		}

		if (!$schema->hasTable('deckghs_usermap')) {
			$table = $schema->createTable('deckghs_usermap');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('map_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('github_login', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('deck_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['map_id', 'github_login'], 'deckghs_um_login');
		}

		return $schema;
	}
}
