<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Service;

use OCA\ZammadBot\Exception\InvalidPayloadException;
use OCA\ZammadBot\Exception\TalkSendException;
use OCA\ZammadBot\Model\NotificationMapper;
use OCA\ZammadBot\Model\Ticket;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Turns a verified webhook body into chat messages.
 */
class WebhookService {
	public function __construct(
		protected PayloadParser $parser,
		protected Config $config,
		protected NotificationMapper $mapper,
		protected MessageFormatter $formatter,
		protected TalkService $talkService,
		protected ITimeFactory $timeFactory,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{sent: int, skipped: int, failed: int, tags: list<string>, configured: list<string>, owner: string, unresolved?: list<string>}
	 * @throws InvalidPayloadException
	 */
	public function handle(string $rawBody): array {
		$ticket = $this->parser->parse($rawBody);
		$tagRooms = $this->config->getTagRooms();
		$configured = array_keys($tagRooms);

		$tags = $ticket->tags;

		$sent = $skipped = $failed = 0;
		$matched = array_values(array_intersect($tags, $configured));
		$report = ['tags' => $tags, 'configured' => $configured, 'owner' => $ticket->owner];

		if ($ticket->isClosed()) {
			$this->logger->debug('Zammad ticket ' . $ticket->id . ' was not announced: it is ' . $ticket->state, $report);
			return ['sent' => 0, 'skipped' => 0, 'failed' => 0] + $report;
		}

		// Checked before the claim, so a ticket that is handed back later can
		// still be announced.
		if ($this->config->onlyUnassigned() && $ticket->hasOwner()) {
			$this->logger->debug('Zammad ticket ' . $ticket->id . ' was not announced: it is owned by '
				. $ticket->owner, $report);
			return ['sent' => 0, 'skipped' => 0, 'failed' => 0] + $report;
		}

		if ($matched === []) {
			// Reported at info because a silent success is the hardest case to
			// diagnose: the webhook looks healthy but nothing is ever posted.
			if ($ticket->unresolved !== []) {
				$report['unresolved'] = $ticket->unresolved;
			}
			$this->logger->info($this->explainNoMatch($ticket, $configured), $report);
			return ['sent' => 0, 'skipped' => 0, 'failed' => 0] + $report;
		}

		$now = $this->timeFactory->getTime();
		foreach ($matched as $tag) {
			$token = $tagRooms[$tag];

			if (!$this->mapper->claim($ticket->id, $tag, $token, $now)) {
				// Already announced, the trigger fired again for a later update.
				$this->mapper->touch($ticket->id, $tag, $now);
				$skipped++;
				continue;
			}

			try {
				$this->talkService->sendMessage(
					$token,
					$this->formatter->format($ticket, $tag),
					hash('sha256', $ticket->id . '|' . $tag),
				);
				$sent++;
			} catch (TalkSendException $e) {
				// Drop the claim so the next trigger run tries again.
				$this->mapper->release($ticket->id, $tag);
				$failed++;
				$this->logger->error('Could not post Zammad ticket ' . $ticket->id . ' to conversation ' . $token, [
					'exception' => $e,
					'tag' => $tag,
				]);
			}
		}

		return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed] + $report;
	}

	/**
	 * @param list<string> $configured
	 */
	protected function explainNoMatch(Ticket $ticket, array $configured): string {
		$message = 'Zammad ticket ' . $ticket->id . ' was not announced: ';
		if ($configured === []) {
			return $message . 'no tag is mapped to a conversation yet, use occ zammad_bot:tag:set';
		}

		if ($ticket->tags === [] && $ticket->unresolved !== []) {
			return $message . 'Zammad could not resolve the tag variable in the webhook payload ('
				. implode(', ', $ticket->unresolved) . '). The webhook must send the tag as a literal string, '
				. 'see the app README';
		}
		if ($ticket->tags === []) {
			return $message . 'the webhook payload carried no tags. Zammad does not include them by default, '
				. 'configure a custom payload that sends the tag, see the app README';
		}
		return $message . 'none of the tags [' . implode(', ', $ticket->tags) . '] '
			. 'is mapped to a conversation [' . implode(', ', $configured) . ']';
	}
}
