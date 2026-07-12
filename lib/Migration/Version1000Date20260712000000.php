<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260712000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('markdownsite_sites')) {
			$t = $schema->createTable('markdownsite_sites');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('owner_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('icon', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('root_file_id', 'bigint', ['notnull' => true]);
			$t->addColumn('root_hint_path', 'string', ['notnull' => false, 'length' => 1024]);
			$t->addColumn('created_at', 'bigint', ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['owner_uid'], 'mdsite_owner_idx');
		}

		if (!$schema->hasTable('markdownsite_shares')) {
			$t = $schema->createTable('markdownsite_shares');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('share_type', 'string', ['notnull' => true, 'length' => 8]);
			$t->addColumn('share_with', 'string', ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['site_id'], 'mdsite_share_site_idx');
			$t->addIndex(['share_with'], 'mdsite_share_with_idx');
		}

		return $schema;
	}
}
