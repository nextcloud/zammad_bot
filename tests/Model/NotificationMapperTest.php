<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Model;

use OCA\ZammadBot\Model\NotificationMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

/**
 * Runs against the real database: the dedupe guarantee is the unique index,
 * which a mock cannot demonstrate.
 */
#[Group('DB')]
class NotificationMapperTest extends TestCase {
	protected const TICKET = 987654321;

	protected IDBConnection $db;
	protected NotificationMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new NotificationMapper($this->db);
		$this->deleteTestRows();
	}

	protected function tearDown(): void {
		$this->deleteTestRows();
		parent::tearDown();
	}

	protected function deleteTestRows(): void {
		$query = $this->db->getQueryBuilder();
		$query->delete(NotificationMapper::TABLE)
			->where($query->expr()->gte('ticket_id', $query->createNamedParameter(self::TICKET)));
		$query->executeStatement();
	}

	protected function notifiedAt(int $ticketId, string $tag): ?int {
		$query = $this->db->getQueryBuilder();
		$query->select('notified_at')
			->from(NotificationMapper::TABLE)
			->where($query->expr()->eq('ticket_id', $query->createNamedParameter($ticketId)))
			->andWhere($query->expr()->eq('tag_hash', $query->createNamedParameter(sha1($tag))));
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : (int)$row['notified_at'];
	}

	public function testFirstClaimWins(): void {
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000));
	}

	public function testSecondClaimForTheSameTicketAndTagIsRefused(): void {
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000));
		$this->assertFalse($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 2000));
	}

	public function testDifferentTagOnTheSameTicketIsAllowed(): void {
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000));
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-support', 'room2', 1000));
	}

	public function testSameTagOnADifferentTicketIsAllowed(): void {
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000));
		$this->assertTrue($this->mapper->claim(self::TICKET + 1, 'team-infra', 'room1', 1000));
	}

	public function testReleaseAllowsAClaimAgain(): void {
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000));
		$this->mapper->release(self::TICKET, 'team-infra');
		$this->assertTrue($this->mapper->claim(self::TICKET, 'team-infra', 'room1', 3000));
	}

	public function testReleaseOnlyRemovesTheGivenTag(): void {
		$this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000);
		$this->mapper->claim(self::TICKET, 'team-support', 'room2', 1000);
		$this->mapper->release(self::TICKET, 'team-infra');

		$this->assertNull($this->notifiedAt(self::TICKET, 'team-infra'));
		$this->assertSame(1000, $this->notifiedAt(self::TICKET, 'team-support'));
	}

	public function testTouchRefreshesTheTimestamp(): void {
		$this->mapper->claim(self::TICKET, 'team-infra', 'room1', 1000);
		$this->mapper->touch(self::TICKET, 'team-infra', 5000);
		$this->assertSame(5000, $this->notifiedAt(self::TICKET, 'team-infra'));
	}

	public function testDeleteOlderThanPrunesOnlyStaleRows(): void {
		$this->mapper->claim(self::TICKET, 'old', 'room1', 1000);
		$this->mapper->claim(self::TICKET, 'fresh', 'room1', 9000);

		$this->assertSame(1, $this->mapper->deleteOlderThan(5000));
		$this->assertNull($this->notifiedAt(self::TICKET, 'old'));
		$this->assertSame(9000, $this->notifiedAt(self::TICKET, 'fresh'));
	}
}
