<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version040000Date20260922000300 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('deckghs_boardmap')) {
			$table = $schema->getTable('deckghs_boardmap');
			if (!$table->hasColumn('github_repository')) {
				$table->addColumn('github_repository', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			}
			if (!$table->hasColumn('start_field_id')) {
				$table->addColumn('start_field_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			}
		}
		return $schema;
	}
}
