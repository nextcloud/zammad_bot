<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Model;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method void setTicketId(int $ticketId)
 * @method int getTicketId()
 * @method void setTag(string $tag)
 * @method string getTag()
 * @method void setTagHash(string $tagHash)
 * @method string getTagHash()
 * @method void setToken(string $token)
 * @method string getToken()
 * @method void setNotifiedAt(int $notifiedAt)
 * @method int getNotifiedAt()
 */
class Notification extends Entity {
	protected int $ticketId = 0;
	protected string $tag = '';
	protected string $tagHash = '';
	protected string $token = '';
	protected int $notifiedAt = 0;

	public function __construct() {
		$this->addType('ticketId', Types::BIGINT);
		$this->addType('tag', Types::STRING);
		$this->addType('tagHash', Types::STRING);
		$this->addType('token', Types::STRING);
		$this->addType('notifiedAt', Types::BIGINT);
	}
}
