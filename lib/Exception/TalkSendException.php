<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Exception;

/**
 * Thrown when posting to the Talk bot API did not result in a created message.
 *
 * The exception code carries the HTTP status when there was a response.
 */
class TalkSendException extends \RuntimeException {
}
