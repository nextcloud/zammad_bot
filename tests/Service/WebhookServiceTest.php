<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Service;

use OCA\ZammadBot\Exception\InvalidPayloadException;
use OCA\ZammadBot\Exception\TalkSendException;
use OCA\ZammadBot\Model\NotificationMapper;
use OCA\ZammadBot\Service\Config;
use OCA\ZammadBot\Service\MessageFormatter;
use OCA\ZammadBot\Service\PayloadParser;
use OCA\ZammadBot\Service\TalkService;
use OCA\ZammadBot\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class WebhookServiceTest extends TestCase {
	protected const NOW = 1_700_000_000;

	protected Config&MockObject $config;
	protected NotificationMapper&MockObject $mapper;
	protected MessageFormatter&MockObject $formatter;
	protected TalkService&MockObject $talkService;
	protected LoggerInterface&MockObject $logger;
	protected WebhookService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
		$this->mapper = $this->createMock(NotificationMapper::class);
		$this->formatter = $this->createMock(MessageFormatter::class);
		$this->talkService = $this->createMock(TalkService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::NOW);

		$this->formatter->method('format')->willReturn('a message');

		$this->service = new WebhookService(
			new PayloadParser(),
			$this->config,
			$this->mapper,
			$this->formatter,
			$this->talkService,
			$timeFactory,
			$this->logger,
		);
	}

	protected function body(array $tags, int $id = 42, array $extra = []): string {
		return json_encode([
			'ticket' => ['id' => $id, 'number' => '1', 'title' => 't', 'tags' => $tags] + $extra,
		], JSON_THROW_ON_ERROR);
	}

	protected function assertCounts(array $expected, array $result): void {
		$this->assertSame($expected, array_intersect_key($result, $expected));
	}

	public function testMatchingTagIsSent(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->mapper->expects($this->once())->method('claim')
			->with(42, 'team-infra', 'room1', self::NOW)->willReturn(true);
		$this->talkService->expects($this->once())->method('sendMessage')
			->with('room1', 'a message', $this->isType('string'));

		$this->assertCounts(['sent' => 1, 'skipped' => 0, 'failed' => 0], $this->service->handle($this->body(['team-infra'])));
	}

	public function testUnconfiguredTagIsIgnored(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->mapper->expects($this->never())->method('claim');
		$this->talkService->expects($this->never())->method('sendMessage');

		$this->assertCounts(['sent' => 0, 'skipped' => 0, 'failed' => 0], $this->service->handle($this->body(['something-else'])));
	}

	public function testOnlyConfiguredTagsAreSent(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->mapper->method('claim')->willReturn(true);
		$this->talkService->expects($this->once())->method('sendMessage')->with('room1', $this->anything(), $this->anything());

		$this->assertSame(1, $this->service->handle($this->body(['urgent', 'team-infra', 'billing']))['sent']);
	}

	public function testTwoTagsGoToTwoRooms(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1', 'team-support' => 'room2']);
		$this->mapper->method('claim')->willReturn(true);

		$tokens = [];
		$this->talkService->method('sendMessage')->willReturnCallback(
			static function (string $token) use (&$tokens): void {
				$tokens[] = $token;
			},
		);

		$this->assertSame(2, $this->service->handle($this->body(['team-infra', 'team-support']))['sent']);
		$this->assertSame(['room1', 'room2'], $tokens);
	}

	public function testAlreadyNotifiedIsSkippedAndRefreshed(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->mapper->method('claim')->willReturn(false);
		$this->mapper->expects($this->once())->method('touch')->with(42, 'team-infra', self::NOW);
		$this->talkService->expects($this->never())->method('sendMessage');

		$this->assertCounts(['sent' => 0, 'skipped' => 1, 'failed' => 0], $this->service->handle($this->body(['team-infra'])));
	}

	public function testFailedSendReleasesTheClaim(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->mapper->method('claim')->willReturn(true);
		$this->talkService->method('sendMessage')->willThrowException(new TalkSendException('nope'));
		$this->mapper->expects($this->once())->method('release')->with(42, 'team-infra');
		$this->logger->expects($this->once())->method('error');

		$this->assertCounts(['sent' => 0, 'skipped' => 0, 'failed' => 1], $this->service->handle($this->body(['team-infra'])));
	}

	public function testTagMatchingIsCaseAndWhitespaceInsensitive(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->mapper->method('claim')->willReturn(true);
		$this->talkService->expects($this->once())->method('sendMessage');

		$this->assertSame(1, $this->service->handle($this->body([' Team-Infra ']))['sent']);
	}

	public function testTicketWithoutTagsDoesNothing(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->talkService->expects($this->never())->method('sendMessage');

		$this->assertCounts(['sent' => 0, 'skipped' => 0, 'failed' => 0], $this->service->handle($this->body([])));
	}

	public function testResultReportsTheTagsItSawAndTheConfiguredOnes(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1', 'team-support' => 'room2']);

		$result = $this->service->handle($this->body(['billing', 'urgent']));
		$this->assertSame(['billing', 'urgent'], $result['tags']);
		$this->assertSame(['team-infra', 'team-support'], $result['configured']);
	}

	public function testNoMatchIsLoggedAtInfoWithBothSides(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->logger->expects($this->once())->method('info')
			->with(
				$this->stringContains('none of the tags [billing]'),
				['tags' => ['billing'], 'configured' => ['team-infra'], 'owner' => ''],
			);

		$this->service->handle($this->body(['billing']));
	}

	public function testPayloadWithoutAnyTagsIsCalledOutExplicitly(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('carried no tags'), $this->anything());

		$this->service->handle($this->body([]));
	}

	public function testEmptyTagMapIsCalledOutExplicitly(): void {
		$this->config->method('getTagRooms')->willReturn([]);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('no tag is mapped to a conversation yet'), $this->anything());

		$this->service->handle($this->body(['team-infra']));
	}

	public function testUnresolvedVariableIsNeverMatchedAsATag(): void {
		$this->config->method('getTagRooms')->willReturn(['#{ticket.tag_list / no such method}' => 'room1']);
		$this->talkService->expects($this->never())->method('sendMessage');

		$result = $this->service->handle($this->body(['#{ticket.tag_list / no such method}']));
		$this->assertSame(0, $result['sent']);
		$this->assertSame([], $result['tags']);
	}

	public function testUnresolvedVariableIsExplainedWithTheLiteralTagHint(): void {
		$this->config->method('getTagRooms')->willReturn(['team-talk' => 'room1']);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('must send the tag as a literal string'), $this->anything());

		$this->service->handle($this->body(['#{ticket.tag_list / no such method}']));
	}

	public function testInvalidPayloadIsPropagated(): void {
		$this->expectException(InvalidPayloadException::class);
		$this->service->handle('not json');
	}

	public function testOwnedTicketIsNotAnnounced(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->config->method('onlyUnassigned')->willReturn(true);
		$this->mapper->expects($this->never())->method('claim');
		$this->talkService->expects($this->never())->method('sendMessage');

		$result = $this->service->handle($this->body(['team-infra'], 42, ['owner_id' => 275, 'owner' => 'Ada Lovelace']));
		$this->assertSame(0, $result['sent']);
		$this->assertSame('Ada Lovelace', $result['owner']);
	}

	/**
	 * Zammad points unassigned tickets at the placeholder user id 1.
	 */
	public function testPlaceholderOwnerCountsAsUnassigned(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->config->method('onlyUnassigned')->willReturn(true);
		$this->mapper->method('claim')->willReturn(true);
		$this->talkService->expects($this->once())->method('sendMessage');

		$this->assertSame(1, $this->service->handle($this->body(['team-infra'], 42, ['owner_id' => 1, 'owner' => '-']))['sent']);
	}

	public function testPayloadWithoutOwnerInformationStillAnnounces(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->config->method('onlyUnassigned')->willReturn(true);
		$this->mapper->method('claim')->willReturn(true);
		$this->talkService->expects($this->once())->method('sendMessage');

		$this->assertSame(1, $this->service->handle($this->body(['team-infra']))['sent']);
	}

	public function testOwnerFilterCanBeDisabled(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->config->method('onlyUnassigned')->willReturn(false);
		$this->mapper->method('claim')->willReturn(true);
		$this->talkService->expects($this->once())->method('sendMessage');

		$this->assertSame(1, $this->service->handle($this->body(['team-infra'], 42, ['owner_id' => 275]))['sent']);
	}

	/**
	 * Nothing is claimed for an owned ticket, so it can still be announced if an
	 * agent hands it back later.
	 */
	public function testOwnedTicketIsNotClaimedSoItCanBeAnnouncedLater(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1']);
		$this->config->method('onlyUnassigned')->willReturn(true);
		$this->mapper->expects($this->never())->method('claim');

		$this->service->handle($this->body(['team-infra'], 42, ['owner_id' => 275]));
	}
}
