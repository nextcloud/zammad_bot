<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Service;

use OCA\ZammadBot\Exception\InvalidPayloadException;
use OCA\ZammadBot\Model\Ticket;

/**
 * Turns a Zammad webhook body into a {@see Ticket}.
 *
 * Zammad's default payload and a custom payload differ in shape: a custom
 * payload delivers every value as a string, and the tag list may arrive as an
 * array, as a comma separated string or as the string rendering of an array.
 * All of those are accepted.
 */
class PayloadParser {
	/**
	 * @throws InvalidPayloadException
	 */
	public function parse(string $rawBody): Ticket {
		try {
			$data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new InvalidPayloadException('Webhook body is not valid JSON', 0, $e);
		}

		if (!is_array($data)) {
			throw new InvalidPayloadException('Webhook body is not a JSON object');
		}

		$ticket = $data['ticket'] ?? null;
		if (!is_array($ticket)) {
			throw new InvalidPayloadException('Webhook body has no ticket object');
		}

		$id = (int)$this->readScalar($ticket, 'id');
		if ($id <= 0) {
			throw new InvalidPayloadException('Webhook body has no usable ticket id');
		}

		[$tags, $unresolved] = $this->readTags($data, $ticket);

		return new Ticket(
			$id,
			$this->readScalar($ticket, 'number'),
			$this->readScalar($ticket, 'title'),
			$tags,
			$this->readNamed($ticket, 'state'),
			$this->readNamed($ticket, 'priority'),
			$this->readNamed($ticket, 'group'),
			$this->readNamed($ticket, 'customer'),
			$this->readResolved($ticket, 'severity', $unresolved),
			$this->readResolved($ticket, 'owner', $unresolved),
			(int)$this->readResolved($ticket, 'owner_id', $unresolved),
			$unresolved,
		);
	}

	/**
	 * Tags may sit on the ticket as `tags` or `tag_list`, next to it at the top
	 * level as `tags`, or as a single literal `tag` set by the webhook.
	 *
	 * Each source is tried in turn and the first one that yields a usable tag
	 * wins, so a variable Zammad failed to render cannot hide a later source
	 * that is perfectly fine.
	 *
	 * @return array{0: list<string>, 1: list<string>} usable tags, unresolved variables
	 */
	protected function readTags(array $data, array $ticket): array {
		$sources = [
			$ticket['tags'] ?? null,
			$ticket['tag_list'] ?? null,
			$data['tags'] ?? null,
			$data['tag'] ?? null,
		];

		$tags = [];
		$unresolved = [];
		foreach ($sources as $source) {
			foreach ($this->splitTags($source) as $tag) {
				// Zammad emits the placeholder verbatim when it cannot render a
				// variable, so it must never be taken for a tag name.
				if (str_contains($tag, '#{')) {
					if (!in_array($tag, $unresolved, true)) {
						$unresolved[] = $tag;
					}
				} elseif ($tags === [] || !in_array($tag, $tags, true)) {
					$tags[] = $tag;
				}
			}

			if ($tags !== []) {
				break;
			}
		}

		return [$tags, $unresolved];
	}

	/**
	 * @return list<string>
	 */
	protected function splitTags(mixed $raw): array {
		if (is_string($raw)) {
			// A rendered array such as ["a", "b"] as well as a plain "a, b" list.
			$raw = explode(',', trim($raw, "[] \t\n\r"));
		}

		if (!is_array($raw)) {
			return [];
		}

		$tags = [];
		foreach ($raw as $tag) {
			if (!is_string($tag) && !is_numeric($tag)) {
				continue;
			}
			$tag = Config::normaliseTag(trim((string)$tag, "\"' \t\n\r"));
			if ($tag !== '' && !in_array($tag, $tags, true)) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}

	/**
	 * Read a value, discarding it when Zammad left an unrendered variable behind.
	 *
	 * @param list<string> $unresolved collected for diagnostics
	 */
	protected function readResolved(array $ticket, string $key, array &$unresolved): string {
		$value = $this->readNamed($ticket, $key);
		if (str_contains($value, '#{')) {
			if (!in_array($value, $unresolved, true)) {
				$unresolved[] = $value;
			}
			return '';
		}
		return $value;
	}

	/**
	 * Read a value that is either a plain scalar or an object carrying a name.
	 */
	protected function readNamed(array $ticket, string $key): string {
		$value = $ticket[$key] ?? null;
		if (!is_array($value)) {
			return $this->readScalar($ticket, $key);
		}

		// Zammad's default payload carries users as firstname/lastname without
		// a composed name, so prefer that over falling back to the login.
		$composed = trim($this->readScalar($value, 'firstname') . ' ' . $this->readScalar($value, 'lastname'));
		if ($composed !== '') {
			return $composed;
		}

		foreach (['fullname', 'name', 'displayname', 'login', 'email'] as $nameKey) {
			if (isset($value[$nameKey]) && is_scalar($value[$nameKey])) {
				return trim((string)$value[$nameKey]);
			}
		}
		return '';
	}

	protected function readScalar(array $ticket, string $key): string {
		$value = $ticket[$key] ?? null;
		return is_scalar($value) ? trim((string)$value) : '';
	}
}
