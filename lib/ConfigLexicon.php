<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;
use OCP\IAppConfig;

class ConfigLexicon implements ILexicon {
	public const string ZAMMAD_URL = 'zammad_url';
	public const string WEBHOOK_SECRET = 'webhook_secret';
	public const string TALK_BOT_SECRET = 'talk_bot_secret';
	public const string TALK_BASE_URL = 'talk_base_url';
	public const string TALK_VERIFY_TLS = 'talk_verify_tls';
	public const string TAG_ROOMS = 'tag_rooms';
	public const string TAG_LEADS = 'tag_leads';
	public const string ESCALATION_SEVERITIES = 'escalation_severities';
	public const string CONFIDENTIAL_GROUPS = 'confidential_groups';
	public const string ONLY_UNASSIGNED = 'only_unassigned';
	public const string RETENTION_DAYS = 'retention_days';

	public const int DEFAULT_RETENTION_DAYS = 90;

	#[\Override]
	public function getStrictness(): Strictness {
		return Strictness::NOTICE;
	}

	#[\Override]
	public function getAppConfigs(): array {
		return [
			new Entry(self::ZAMMAD_URL, ValueType::STRING, '', definition: 'Base URL of the Zammad instance, used to link tickets'),
			new Entry(self::WEBHOOK_SECRET, ValueType::STRING, '', definition: 'Secret Zammad signs the webhook payloads with', flags: IAppConfig::FLAG_SENSITIVE),
			new Entry(self::TALK_BOT_SECRET, ValueType::STRING, '', definition: 'Secret of the Talk bot (40 to 128 characters)', flags: IAppConfig::FLAG_SENSITIVE),
			new Entry(self::TALK_BASE_URL, ValueType::STRING, '', definition: 'Base URL to reach the Talk API of this server, empty to derive it from the request'),
			new Entry(self::TALK_VERIFY_TLS, ValueType::BOOL, true, definition: 'Whether the TLS certificate is verified when posting to Talk'),
			new Entry(self::TAG_ROOMS, ValueType::ARRAY, [], definition: 'Map of Zammad tag to Talk conversation token'),
			new Entry(self::TAG_LEADS, ValueType::ARRAY, [], definition: 'Map of Zammad tag to the user id or `group/<id>` mentioned on escalation'),
			new Entry(self::ESCALATION_SEVERITIES, ValueType::ARRAY, [], definition: 'Severities that make the bot mention the team lead'),
			new Entry(self::CONFIDENTIAL_GROUPS, ValueType::ARRAY, [], definition: 'Zammad groups whose ticket title and customer are not posted into Talk'),
			new Entry(self::ONLY_UNASSIGNED, ValueType::BOOL, true, definition: 'Whether tickets that an agent already took are skipped'),
			new Entry(self::RETENTION_DAYS, ValueType::INT, self::DEFAULT_RETENTION_DAYS, definition: 'Days after which sent notifications are cleaned up (minimum 1)'),
		];
	}

	#[\Override]
	public function getUserConfigs(): array {
		return [];
	}
}
