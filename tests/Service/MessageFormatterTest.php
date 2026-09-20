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
		$this->config->method('getEscalationSeverities')->willReturn(['sev1', 'sev2']);
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
			$overrides['severity'] ?? '',
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

	public function testSeverityIsShownWhenPresent(): void {
		$this->assertStringContainsString(
			'Severity: sev3',
			$this->formatter->format($this->ticket(['severity' => 'sev3']), 'talk'),
		);
	}

	public function testSevereTicketMentionsTheLead(): void {
		$this->config->method('getTagLead')->with('talk')->willReturn('ada');

		$message = $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk');
		$this->assertStringContainsString('❗ **sev1** — @"ada"', $message);
	}

	public function testSev2AlsoMentions(): void {
		$this->config->method('getTagLead')->willReturn('ada');
		$this->assertStringContainsString('@"ada"', $this->formatter->format($this->ticket(['severity' => 'sev2']), 'talk'));
	}

	public function testGroupLeadIsMentionedAsAGroup(): void {
		$this->config->method('getTagLead')->willReturn('group/talk-leads');
		$this->assertStringContainsString('@"group/talk-leads"', $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk'));
	}

	public function testLowSeverityDoesNotMention(): void {
		$this->config->method('getTagLead')->willReturn('ada');
		$this->assertStringNotContainsString('@"ada"', $this->formatter->format($this->ticket(['severity' => 'sev4']), 'talk'));
	}

	public function testSeverityMatchingIgnoresCase(): void {
		$this->config->method('getTagLead')->willReturn('ada');
		$this->assertStringContainsString('@"ada"', $this->formatter->format($this->ticket(['severity' => 'SEV1']), 'talk'));
	}

	public function testWithoutASeverityNobodyIsMentioned(): void {
		$this->config->method('getTagLead')->willReturn('ada');
		$this->assertStringNotContainsString('@"', $this->formatter->format($this->ticket(), 'talk'));
	}

	public function testWithoutALeadNobodyIsMentioned(): void {
		$this->config->method('getTagLead')->willReturn('');
		$this->assertStringNotContainsString('@"', $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk'));
	}

	/**
	 * An id core's mention parser would not accept must not be emitted at all,
	 * it would read as plain text and notify nobody.
	 */
	public function testUnmentionableLeadIsDropped(): void {
		$this->config->method('getTagLead')->willReturn('not a *valid* id!');
		$this->assertStringNotContainsString('@"', $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk'));
	}

	/**
	 * The mention must survive the Markdown escaping, an escaped underscore
	 * would break the id and notify nobody.
	 */
	public function testMentionIsNotMarkdownEscaped(): void {
		$this->config->method('getTagLead')->willReturn('ada_lovelace');
		$this->assertStringContainsString('@"ada_lovelace"', $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk'));
	}

	/**
	 * Core strips mentions inside code spans, so the mention may never end up
	 * on the same construct as the tag.
	 */
	public function testMentionIsNotInsideACodeSpan(): void {
		$this->config->method('getTagLead')->willReturn('ada');
		$message = $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk');
		$stripped = preg_replace('/`[^`\n]*`/', '', $message);
		$this->assertStringContainsString('@"ada"', $stripped);
	}

	public function testEscalationIsItsOwnLine(): void {
		$this->config->method('getTagLead')->willReturn('ada');
		$lines = explode("\n", $this->formatter->format($this->ticket(['severity' => 'sev1']), 'talk'));
		$this->assertCount(3, $lines);
		$this->assertStringStartsWith('❗', $lines[2]);
	}
}
