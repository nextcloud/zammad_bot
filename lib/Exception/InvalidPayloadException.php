<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Exception;

/**
 * Thrown when a webhook body cannot be read as a Zammad ticket.
 */
class InvalidPayloadException extends \RuntimeException {
}
