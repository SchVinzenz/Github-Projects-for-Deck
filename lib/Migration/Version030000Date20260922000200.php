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

class Version030000Date20260922000200 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('deckghs_itemmap')) {
			$table = $schema->getTable('deckghs_itemmap');
			if (!$table->hasColumn('deck_hash')) {
				$table->addColumn('deck_hash', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
			}
			if (!$table->hasColumn('github_hash')) {
				$table->addColumn('github_hash', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
			}
		}

		return $schema;
	}
}
