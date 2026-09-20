<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Service;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Typed access to the app configuration.
 *
 * This is the only class reading or writing app config, so everything else can
 * be unit tested against a mock of it.
 */
class Config {
	public const DEFAULT_RETENTION_DAYS = 90;

	/** Conversation tokens as accepted by Talk's bot routes. */
	private const TOKEN_REGEX = '/^[a-z0-9]{4,30}$/';

	public function __construct(
		protected IAppConfig $appConfig,
	) {
	}

	public function getZammadUrl(): string {
		return $this->appConfig->getAppValueString('zammad_url');
	}

	/**
	 * @throws \InvalidArgumentException when the URL is not http(s)
	 */
	public function setZammadUrl(string $url): void {
		$url = rtrim(trim($url), '/');
		if (!preg_match('/^https?:\/\/\S+$/', $url)) {
			throw new \InvalidArgumentException('Zammad URL must start with http:// or https://');
		}
		$this->appConfig->setAppValueString('zammad_url', $url);
	}

	public function getWebhookSecret(): string {
		return $this->appConfig->getAppValueString('webhook_secret');
	}

	public function setWebhookSecret(string $secret): void {
		$secret = trim($secret);
		if ($secret === '') {
			throw new \InvalidArgumentException('Webhook secret must not be empty');
		}
		$this->appConfig->setAppValueString('webhook_secret', $secret, sensitive: true);
	}

	public function getTalkBotSecret(): string {
		return $this->appConfig->getAppValueString('talk_bot_secret');
	}

	public function setTalkBotSecret(string $secret): void {
		$secret = trim($secret);
		if (strlen($secret) < 40 || strlen($secret) > 128) {
			throw new \InvalidArgumentException('Talk bot secret must be between 40 and 128 characters');
		}
		$this->appConfig->setAppValueString('talk_bot_secret', $secret, sensitive: true);
	}

	/**
	 * @return array<string, string> normalised tag => conversation token
	 */
	public function getTagRooms(): array {
		$map = [];
		foreach ($this->appConfig->getAppValueArray('tag_rooms') as $tag => $token) {
			if (is_string($tag) && is_string($token)) {
				$map[self::normaliseTag($tag)] = $token;
			}
		}
		return $map;
	}

	/**
	 * @throws \InvalidArgumentException when the tag is empty or the token is malformed
	 */
	public function setTagRoom(string $tag, string $token): void {
		$tag = self::normaliseTag($tag);
		if ($tag === '') {
			throw new \InvalidArgumentException('Tag must not be empty');
		}
		if (!preg_match(self::TOKEN_REGEX, $token)) {
			throw new \InvalidArgumentException('"' . $token . '" is not a valid conversation token');
		}

		$map = $this->getTagRooms();
		$map[$tag] = $token;
		$this->appConfig->setAppValueArray('tag_rooms', $map);
	}

	/**
	 * @return bool whether the tag was mapped before
	 */
	public function removeTagRoom(string $tag): bool {
		$tag = self::normaliseTag($tag);
		$map = $this->getTagRooms();
		if (!isset($map[$tag])) {
			return false;
		}

		unset($map[$tag]);
		$this->appConfig->setAppValueArray('tag_rooms', $map);
		return true;
	}

	/**
	 * Base URL used to reach this server's own Talk API. Empty when the caller
	 * should derive it from the current request.
	 */
	public function getTalkBaseUrl(): string {
		return rtrim($this->appConfig->getAppValueString('talk_base_url'), '/');
	}

	public function setTalkBaseUrl(string $url): void {
		$url = rtrim(trim($url), '/');
		if ($url !== '' && !preg_match('/^https?:\/\/\S+$/', $url)) {
			throw new \InvalidArgumentException('Talk base URL must start with http:// or https://');
		}
		$this->appConfig->setAppValueString('talk_base_url', $url);
	}

	public function verifyTls(): bool {
		return $this->appConfig->getAppValueBool('talk_verify_tls', true);
	}

	public function setVerifyTls(bool $verify): void {
		$this->appConfig->setAppValueBool('talk_verify_tls', $verify);
	}

	public function getRetentionSeconds(): int {
		return $this->getRetentionDays() * 24 * 3600;
	}

	public function getRetentionDays(): int {
		return max(1, $this->appConfig->getAppValueInt('retention_days', self::DEFAULT_RETENTION_DAYS));
	}

	public function setRetentionDays(int $days): void {
		if ($days < 1) {
			throw new \InvalidArgumentException('Retention must be at least one day');
		}
		$this->appConfig->setAppValueInt('retention_days', $days);
	}

	public static function normaliseTag(string $tag): string {
		return mb_strtolower(trim($tag));
	}
}
