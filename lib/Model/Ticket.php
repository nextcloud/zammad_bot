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
	 * Zammad has no null owner: an unassigned ticket points at the built-in
	 * placeholder user, which is always id 1 and renders as "-".
	 */
	public const UNASSIGNED_OWNER_ID = 1;

	public const UNASSIGNED_OWNER_NAME = '-';

	/**
	 * Zammad's built-in states of the "closed", "merged" and "removed" types.
	 */
	public const CLOSED_STATES = ['closed', 'merged', 'removed'];

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
		public readonly string $severity = '',
		public readonly string $owner = '',
		public readonly int $ownerId = 0,
		public readonly array $unresolved = [],
	) {
	}

	/**
	 * Whether an agent has taken the ticket.
	 *
	 * When the payload says nothing about the owner this reports false, so an
	 * incomplete payload still notifies rather than silently dropping tickets.
	 */
	public function hasOwner(): bool {
		if ($this->ownerId > 0) {
			return $this->ownerId !== self::UNASSIGNED_OWNER_ID;
		}

		$owner = trim($this->owner);
		return $owner !== '' && $owner !== self::UNASSIGNED_OWNER_NAME;
	}

	public function isClosed(): bool {
		return in_array(strtolower(trim($this->state)), self::CLOSED_STATES, true);
	}
}
