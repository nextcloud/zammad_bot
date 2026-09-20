<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\BackgroundJob;

use OCA\ZammadBot\Model\NotificationMapper;
use OCA\ZammadBot\Service\Config;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Prunes dedupe rows of tickets that have been quiet for the retention window.
 */
class CleanUpNotifications extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		protected NotificationMapper $mapper,
		protected Config $config,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->mapper->deleteOlderThan($this->time->getTime() - $this->config->getRetentionSeconds());
	}
}
