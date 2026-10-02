<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Full-text search index (1.5.0). */
class Version1500Date20261002000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('markdownsite_search')) {
			$t = $schema->createTable('markdownsite_search');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 1024]);
			$t->addColumn('path_hash', Types::STRING, ['notnull' => true, 'length' => 40]);
			$t->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('title_norm', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('aliases_norm', Types::TEXT, ['notnull' => false]);
			$t->addColumn('headings_norm', Types::TEXT, ['notnull' => false]);
			$t->addColumn('body', Types::TEXT, ['notnull' => false]);
			$t->addColumn('body_norm', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['site_id'], 'mdsite_search_site_idx');
			$t->addUniqueIndex(['site_id', 'path_hash'], 'mdsite_search_path_uq');
		}

		$sites = $schema->getTable('markdownsite_sites');
		if (!$sites->hasColumn('search_etag')) {
			$sites->addColumn('search_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		return $schema;
	}
}
