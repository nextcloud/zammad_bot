<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260920103000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('zammad_bot_notified')) {
			return null;
		}

		$table = $schema->createTable('zammad_bot_notified');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('ticket_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('tag', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// Hashed so the unique index stays within the key length limit of MySQL.
		$table->addColumn('tag_hash', Types::STRING, [
			'notnull' => true,
			'length' => 40,
		]);
		$table->addColumn('token', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('notified_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['ticket_id', 'tag_hash'], 'zammad_bot_notif_uniq');
		$table->addIndex(['notified_at'], 'zammad_bot_notif_time');
		return $schema;
	}
}
