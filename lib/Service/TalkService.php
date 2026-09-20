<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Service;

use OCA\ZammadBot\Exception\TalkSendException;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Posts messages through Talk's bot API.
 *
 * The bot is registered with `occ talk:bot:create`, which yields a
 * `responseonly://` bot that may post but is never invoked itself.
 */
class TalkService {
	private const CONNECT_TIMEOUT = 5;
	private const TIMEOUT = 10;

	/** Talk requires at least 32 characters of randomness in the signature seed. */
	private const RANDOM_LENGTH = 64;

	public function __construct(
		protected IClientService $clientService,
		protected IURLGenerator $urlGenerator,
		protected ISecureRandom $secureRandom,
		protected Config $config,
	) {
	}

	/**
	 * @throws TalkSendException when the message was not created
	 */
	public function sendMessage(string $token, string $message, string $referenceId = ''): void {
		$secret = $this->config->getTalkBotSecret();
		if ($secret === '') {
			throw new TalkSendException('No Talk bot secret configured, run occ talk:bot:create first');
		}

		$random = $this->secureRandom->generate(self::RANDOM_LENGTH, ISecureRandom::CHAR_HUMAN_READABLE);
		$signature = hash_hmac('sha256', $random . $message, $secret);

		$body = json_encode([
			'message' => $message,
			'referenceId' => $referenceId,
		], JSON_THROW_ON_ERROR);

		try {
			$response = $this->clientService->newClient()->post($this->messageUrl($token), [
				'body' => $body,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
					'OCS-APIRequest' => 'true',
					'X-Nextcloud-Talk-Bot-Random' => $random,
					'X-Nextcloud-Talk-Bot-Signature' => $signature,
				],
				'timeout' => self::TIMEOUT,
				'connect_timeout' => self::CONNECT_TIMEOUT,
				'verify' => $this->config->verifyTls(),
				// The call targets this very server, which the client blocks by default.
				'nextcloud' => [
					'allow_local_address' => true,
				],
			]);
		} catch (\Throwable $e) {
			throw new TalkSendException('Request to the Talk bot API failed: ' . $e->getMessage(), 0, $e);
		}

		$status = $response->getStatusCode();
		if ($status !== Http::STATUS_CREATED) {
			throw new TalkSendException($this->explain($status, $token), $status);
		}
	}

	protected function messageUrl(string $token): string {
		$base = $this->config->getTalkBaseUrl();
		if ($base === '') {
			$base = rtrim($this->urlGenerator->getAbsoluteURL(''), '/');
		}
		return $base . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $token . '/message';
	}

	protected function explain(int $status, string $token): string {
		$hint = match ($status) {
			Http::STATUS_UNAUTHORIZED => ' - the bot is most likely not set up in this conversation, run occ talk:bot:setup <bot-id> ' . $token,
			Http::STATUS_NOT_FOUND => ' - conversation ' . $token . ' does not exist',
			Http::STATUS_REQUEST_ENTITY_TOO_LARGE => ' - the message exceeded the length Talk accepts',
			default => '',
		};
		return 'Talk bot API returned ' . $status . $hint;
	}
}
