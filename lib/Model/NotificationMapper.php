<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Remembers which ticket was already announced for which tag.
 *
 * A Zammad trigger fires again on every later update of the ticket while the
 * tag is still set, so without this the same ticket would be posted repeatedly.
 *
 * @template-extends QBMapper<Notification>
 */
class NotificationMapper extends QBMapper {
	public const TABLE = 'zammad_bot_notified';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Notification::class);
	}

	/**
	 * Claim the right to announce this ticket for this tag.
	 *
	 * The insert is what makes the claim, so two webhooks arriving at the same
	 * time cannot both win.
	 *
	 * @return bool false when it was already claimed and nothing should be sent
	 */
	public function claim(int $ticketId, string $tag, string $token, int $timestamp): bool {
		return $this->db->insertIgnoreConflict(self::TABLE, [
			'ticket_id' => $ticketId,
			'tag' => $tag,
			'tag_hash' => $this->hash($tag),
			'token' => $token,
			'notified_at' => $timestamp,
		]) === 1;
	}

	/**
	 * Give the claim back so a later trigger run can retry a failed send.
	 */
	public function release(int $ticketId, string $tag): void {
		$query = $this->db->getQueryBuilder();
		$query->delete(self::TABLE)
			->where($query->expr()->eq('ticket_id', $query->createNamedParameter($ticketId)))
			->andWhere($query->expr()->eq('tag_hash', $query->createNamedParameter($this->hash($tag))));
		$query->executeStatement();
	}

	/**
	 * Keep a still active ticket from ageing out of the retention window and
	 * being announced a second time.
	 */
	public function touch(int $ticketId, string $tag, int $timestamp): void {
		$query = $this->db->getQueryBuilder();
		$query->update(self::TABLE)
			->set('notified_at', $query->createNamedParameter($timestamp))
			->where($query->expr()->eq('ticket_id', $query->createNamedParameter($ticketId)))
			->andWhere($query->expr()->eq('tag_hash', $query->createNamedParameter($this->hash($tag))));
		$query->executeStatement();
	}

	/**
	 * @return int number of removed rows
	 */
	public function deleteOlderThan(int $timestamp): int {
		$query = $this->db->getQueryBuilder();
		$query->delete(self::TABLE)
			->where($query->expr()->lt('notified_at', $query->createNamedParameter($timestamp)));
		return $query->executeStatement();
	}

	protected function hash(string $tag): string {
		return sha1($tag);
	}
}
