<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Service;

use OCA\ZammadBot\Model\Ticket;

/**
 * Renders a ticket as the Markdown message posted into Talk.
 */
class MessageFormatter {
	/** Talk rejects anything longer, see ChatManager::MAX_CHAT_LENGTH. */
	public const MAX_MESSAGE_LENGTH = 32000;

	/** Keeps a pathological title from crowding out the rest of the message. */
	public const MAX_TITLE_LENGTH = 500;

	public function __construct(
		protected Config $config,
	) {
	}

	public function format(Ticket $ticket, string $matchedTag): string {
		$title = $this->escape($this->truncate($ticket->title, self::MAX_TITLE_LENGTH));
		$label = $ticket->number !== '' ? '#' . $ticket->number . ' ' . $title : $title;
		if (trim($label) === '') {
			$label = '#' . $ticket->id;
		}

		$link = $this->ticketUrl($ticket);
		$headline = $link !== ''
			? '🎫 **[' . $label . '](' . $link . ')**'
			: '🎫 **' . $label . '**';

		$details = [];
		foreach ([
			'Customer' => $ticket->customer,
			'Group' => $ticket->group,
			'State' => $ticket->state,
			'Priority' => $ticket->priority,
		] as $caption => $value) {
			if (trim($value) !== '') {
				$details[] = $caption . ': ' . $this->escape(trim($value));
			}
		}

		$message = $details === [] ? $headline : $headline . "\n" . implode("\n", $details);
		return $this->truncate($message, self::MAX_MESSAGE_LENGTH);
	}

	protected function ticketUrl(Ticket $ticket): string {
		$base = rtrim($this->config->getZammadUrl(), '/');
		return $base === '' ? '' : $base . '/#ticket/zoom/' . $ticket->id;
	}

	/**
	 * Keep Markdown syntax in ticket data from breaking out of the link label
	 * or the message layout. Only characters that are special mid-line are
	 * escaped, so ordinary text such as "2nd-Level" stays readable.
	 */
	protected function escape(string $text): string {
		$text = $this->singleLine($text);
		return preg_replace('/([\\\\`*_~\[\]])/', '\\\\$1', $text) ?? $text;
	}

	/**
	 * A code span needs no escaping, it only must not be ended early.
	 */
	protected function code(string $text): string {
		return str_replace('`', '', $this->singleLine($text));
	}

	protected function singleLine(string $text): string {
		return str_replace(["\r\n", "\r", "\n"], ' ', $text);
	}

	protected function truncate(string $text, int $length): string {
		if (mb_strlen($text) <= $length) {
			return $text;
		}
		return mb_substr($text, 0, $length - 1) . '…';
	}
}
