<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Service;

use Psr\Log\LoggerInterface;

/**
 * Verifies the `X-Hub-Signature` header Zammad sends with every webhook.
 *
 * Zammad signs the raw request body with HMAC-SHA1 and formats the header as
 * `sha1=<hex>`.
 */
class SignatureService {
	public const HEADER = 'x-hub-signature';

	private const PREFIX = 'sha1=';

	public function __construct(
		protected Config $config,
		protected LoggerInterface $logger,
	) {
	}

	public function isValid(string $rawBody, string $header): bool {
		$secret = $this->config->getWebhookSecret();
		if ($secret === '') {
			$this->logger->error('No Zammad webhook secret configured, rejecting request');
			return false;
		}

		$header = strtolower(trim($header));
		if (!str_starts_with($header, self::PREFIX)) {
			return false;
		}

		$expected = hash_hmac('sha1', $rawBody, $secret);
		return hash_equals($expected, substr($header, strlen(self::PREFIX)));
	}

	/**
	 * Build the header value for a body, so the setup documentation and the
	 * tests do not have to repeat the format.
	 */
	public function sign(string $rawBody, string $secret): string {
		return self::PREFIX . hash_hmac('sha1', $rawBody, $secret);
	}
}
