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
		$headline .= ' — `' . $this->code($matchedTag) . '`';

		$details = [];
		foreach ([
			'Requester' => $ticket->customer,
			'Group' => $ticket->group,
			'State' => $ticket->state,
			'Priority' => $ticket->priority,
			'Severity' => $ticket->severity,
		] as $caption => $value) {
			if (trim($value) !== '') {
				$details[] = $caption . ': ' . $this->escape(trim($value));
			}
		}

		$lines = [$headline];
		if ($details !== []) {
			$lines[] = implode(' · ', $details);
		}
		if (($escalation = $this->escalation($ticket, $matchedTag)) !== '') {
			$lines[] = $escalation;
		}

		return $this->truncate(implode("\n", $lines), self::MAX_MESSAGE_LENGTH);
	}

	/**
	 * Mentions the team lead when the ticket is severe enough to warrant it.
	 *
	 * The mention is neither escaped nor put in a code span, core's mention
	 * parser ignores it in both cases and nobody would be notified.
	 */
	protected function escalation(Ticket $ticket, string $matchedTag): string {
		$severity = mb_strtolower(trim($ticket->severity));
		if ($severity === '' || !in_array($severity, $this->config->getEscalationSeverities(), true)) {
			return '';
		}

		$lead = trim($this->config->getTagLead($matchedTag));
		if ($lead === '' || !Config::isMentionable($lead)) {
			return '';
		}

		return '❗ **' . $this->escape(trim($ticket->severity)) . '** — @"' . $lead . '"';
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
