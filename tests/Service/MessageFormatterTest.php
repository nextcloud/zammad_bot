<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Service;

use OCA\ZammadBot\Model\Ticket;
use OCA\ZammadBot\Service\Config;
use OCA\ZammadBot\Service\MessageFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class MessageFormatterTest extends TestCase {
	protected Config&MockObject $config;
	protected MessageFormatter $formatter;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
		$this->config->method('getZammadUrl')->willReturn('https://zammad.example.com');
		$this->formatter = new MessageFormatter($this->config);
	}

	protected function ticket(array $overrides = []): Ticket {
		return new Ticket(
			$overrides['id'] ?? 42,
			$overrides['number'] ?? '1234',
			$overrides['title'] ?? 'Printer on fire',
			$overrides['tags'] ?? ['team-infra'],
			$overrides['state'] ?? 'open',
			$overrides['priority'] ?? '2 normal',
			$overrides['group'] ?? 'Users',
			$overrides['customer'] ?? 'Ada Lovelace',
		);
	}

	public function testFullTicket(): void {
		$this->assertSame(
			"🎫 **[#1234 Printer on fire](https://zammad.example.com/#ticket/zoom/42)** — `team-infra`\n"
			. 'Requester: Ada Lovelace · Group: Users · State: open · Priority: 2 normal',
			$this->formatter->format($this->ticket(), 'team-infra'),
		);
	}

	public function testOptionalFieldsAreOmitted(): void {
		$message = $this->formatter->format(
			$this->ticket(['state' => '', 'priority' => '', 'group' => '', 'customer' => '']),
			'team-infra',
		);
		$this->assertSame('🎫 **[#1234 Printer on fire](https://zammad.example.com/#ticket/zoom/42)** — `team-infra`', $message);
	}

	public function testTrailingSlashOnZammadUrl(): void {
		$config = $this->createMock(Config::class);
		$config->method('getZammadUrl')->willReturn('https://zammad.example.com/');
		$formatter = new MessageFormatter($config);
		$this->assertStringContainsString('(https://zammad.example.com/#ticket/zoom/42)', $formatter->format($this->ticket(), 'team-infra'));
	}

	public function testWithoutZammadUrlNoLinkIsBuilt(): void {
		$config = $this->createMock(Config::class);
		$config->method('getZammadUrl')->willReturn('');
		$formatter = new MessageFormatter($config);
		$message = $formatter->format($this->ticket(), 'team-infra');
		$this->assertStringNotContainsString('](', $message);
		$this->assertStringContainsString('#1234 Printer on fire', $message);
	}

	public function testMarkdownInTitleIsEscaped(): void {
		$message = $this->formatter->format($this->ticket(['title' => 'Fix [urgent] *now* _please_']), 'team-infra');
		$this->assertStringContainsString('\\[urgent\\] \\*now\\* \\_please\\_', $message);
	}

	public function testNewlinesInTitleAreFlattened(): void {
		$message = $this->formatter->format($this->ticket(['title' => "line one\nline two"]), 'team-infra');
		$this->assertStringContainsString('line one line two', $message);
		$this->assertSame(2, substr_count($message, "\n") + 1);
	}

	public function testHyphenatedValuesAreNotEscaped(): void {
		$message = $this->formatter->format($this->ticket(['group' => '2nd-Level']), 'team-infra');
		$this->assertStringContainsString('Group: 2nd-Level', $message);
	}

	public function testBacktickInTagCannotBreakTheCodeSpan(): void {
		$message = $this->formatter->format($this->ticket(), 'team`infra');
		$this->assertStringContainsString('`teaminfra`', $message);
	}

	public function testLongTitleIsTruncated(): void {
		$message = $this->formatter->format($this->ticket(['title' => str_repeat('a', 40000)]), 'team-infra');
		$this->assertLessThanOrEqual(MessageFormatter::MAX_MESSAGE_LENGTH, mb_strlen($message));
		$this->assertStringContainsString('…', $message);
	}

	public function testTruncationIsMultibyteSafe(): void {
		$message = $this->formatter->format($this->ticket(['title' => str_repeat('日', 40000)]), 'team-infra');
		$this->assertLessThanOrEqual(MessageFormatter::MAX_MESSAGE_LENGTH, mb_strlen($message));
		$this->assertSame($message, mb_convert_encoding($message, 'UTF-8', 'UTF-8'));
	}

	public function testTicketWithoutNumberFallsBackToTitle(): void {
		$message = $this->formatter->format($this->ticket(['number' => '']), 'team-infra');
		$this->assertStringContainsString('[Printer on fire]', $message);
	}

	public function testTicketWithoutNumberAndTitleFallsBackToId(): void {
		$message = $this->formatter->format($this->ticket(['number' => '', 'title' => '']), 'team-infra');
		$this->assertStringContainsString('[#42]', $message);
	}

	public function testMatchedTagIsShown(): void {
		$this->assertStringContainsString('`team-support`', $this->formatter->format($this->ticket(), 'team-support'));
	}
}
