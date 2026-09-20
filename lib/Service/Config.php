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

	/** @var list<string> */
	public const DEFAULT_SEVERITIES = ['sev1', 'sev2'];

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
	 * @return array<string, string> normalised tag => the lead to mention
	 */
	public function getTagLeads(): array {
		$map = [];
		foreach ($this->appConfig->getAppValueArray('tag_leads') as $tag => $lead) {
			if (is_string($tag) && is_string($lead)) {
				$map[self::normaliseTag($tag)] = $lead;
			}
		}
		return $map;
	}

	public function getTagLead(string $tag): string {
		return $this->getTagLeads()[self::normaliseTag($tag)] ?? '';
	}

	/**
	 * @param string $lead a user id, or `group/<id>` to mention a whole group
	 * @throws \InvalidArgumentException when the id cannot be used in a mention
	 */
	public function setTagLead(string $tag, string $lead): void {
		$tag = self::normaliseTag($tag);
		if ($tag === '') {
			throw new \InvalidArgumentException('Tag must not be empty');
		}
		if (!self::isMentionable($lead)) {
			throw new \InvalidArgumentException('"' . $lead . '" cannot be mentioned, use a user id or group/<id>');
		}

		$map = $this->getTagLeads();
		$map[$tag] = trim($lead);
		$this->appConfig->setAppValueArray('tag_leads', $map);
	}

	public function removeTagLead(string $tag): bool {
		$tag = self::normaliseTag($tag);
		$map = $this->getTagLeads();
		if (!isset($map[$tag])) {
			return false;
		}

		unset($map[$tag]);
		$this->appConfig->setAppValueArray('tag_leads', $map);
		return true;
	}

	/**
	 * Severities that make the bot mention the team lead.
	 *
	 * @return list<string>
	 */
	public function getEscalationSeverities(): array {
		$values = $this->appConfig->getAppValueArray('escalation_severities', self::DEFAULT_SEVERITIES);
		$severities = [];
		foreach ($values as $value) {
			if (is_string($value) && trim($value) !== '') {
				$severities[] = mb_strtolower(trim($value));
			}
		}
		return $severities === [] ? [] : array_values(array_unique($severities));
	}

	/**
	 * @param list<string> $severities
	 */
	public function setEscalationSeverities(array $severities): void {
		$this->appConfig->setAppValueArray('escalation_severities', array_values(array_unique(
			array_map(static fn (string $s): string => mb_strtolower(trim($s)), $severities),
		)));
	}

	/**
	 * Only ids core's mention parser accepts are useful, anything else would be
	 * rendered as plain text and notify nobody.
	 */
	public static function isMentionable(string $lead): bool {
		$lead = trim($lead);
		if (str_starts_with($lead, 'group/') || str_starts_with($lead, 'team/')) {
			return (bool)preg_match('/^(group|team)\/[a-z0-9_\-@\.\' \/:]+$/i', $lead);
		}
		return (bool)preg_match('/^[a-z0-9_\-@\.\' ]+$/i', $lead);
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

	/**
	 * Whether tickets that an agent already took should be skipped.
	 */
	public function onlyUnassigned(): bool {
		return $this->appConfig->getAppValueBool('only_unassigned', true);
	}

	public function setOnlyUnassigned(bool $onlyUnassigned): void {
		$this->appConfig->setAppValueBool('only_unassigned', $onlyUnassigned);
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
