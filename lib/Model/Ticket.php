<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Model;

/**
 * The parts of a Zammad ticket the bot reports on.
 *
 * @psalm-immutable
 */
class Ticket {
	/**
	 * @param list<string> $tags normalised to lowercase
	 * @param list<string> $unresolved Zammad variables the payload did not render,
	 *                                 kept for diagnostics and never matched
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $number,
		public readonly string $title,
		public readonly array $tags,
		public readonly string $state = '',
		public readonly string $priority = '',
		public readonly string $group = '',
		public readonly string $customer = '',
		public readonly array $unresolved = [],
	) {
	}
}
