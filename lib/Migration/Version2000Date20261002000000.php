<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Per-share role, "reader" or "editor" (2.0.0). Existing shares become readers. */
class Version2000Date20261002000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$shares = $schema->getTable('markdownsite_shares');
		if (!$shares->hasColumn('role')) {
			$shares->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'reader']);
		}
		return $schema;
	}
}
