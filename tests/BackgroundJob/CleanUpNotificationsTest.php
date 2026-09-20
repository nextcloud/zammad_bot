<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\BackgroundJob;

use OCA\ZammadBot\BackgroundJob\CleanUpNotifications;
use OCA\ZammadBot\Model\NotificationMapper;
use OCA\ZammadBot\Service\Config;
use OCP\AppFramework\Utility\ITimeFactory;
use Test\TestCase;

class CleanUpNotificationsTest extends TestCase {
	protected const NOW = 1_700_000_000;

	public function testPrunesRowsOlderThanTheRetentionWindow(): void {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::NOW);

		$config = $this->createMock(Config::class);
		$config->method('getRetentionSeconds')->willReturn(90 * 24 * 3600);

		$mapper = $this->createMock(NotificationMapper::class);
		$mapper->expects($this->once())->method('deleteOlderThan')
			->with(self::NOW - (90 * 24 * 3600));

		self::invokePrivate(new CleanUpNotifications($timeFactory, $mapper, $config), 'run', [null]);
	}

	public function testHonoursACustomRetention(): void {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::NOW);

		$config = $this->createMock(Config::class);
		$config->method('getRetentionSeconds')->willReturn(7 * 24 * 3600);

		$mapper = $this->createMock(NotificationMapper::class);
		$mapper->expects($this->once())->method('deleteOlderThan')
			->with(self::NOW - (7 * 24 * 3600));

		self::invokePrivate(new CleanUpNotifications($timeFactory, $mapper, $config), 'run', [null]);
	}
}
