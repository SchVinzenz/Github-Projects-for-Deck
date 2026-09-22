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

class Version010000Date20260922000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('deckghs_boardmap')) {
			$table = $schema->createTable('deckghs_boardmap');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('deck_board_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('github_project_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('github_owner', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('github_number', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'both']);
			$table->addColumn('field_config', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status_field_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('last_sync', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'deckghs_bm_user');
		}

		if (!$schema->hasTable('deckghs_itemmap')) {
			$table = $schema->createTable('deckghs_itemmap');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('map_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('deck_card_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('github_item_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('github_content_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('content_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'DraftIssue']);
			$table->addColumn('sync_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['map_id', 'deck_card_id'], 'deckghs_im_card');
			$table->addUniqueIndex(['map_id', 'github_item_id'], 'deckghs_im_item');
		}

		return $schema;
	}
}
