<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Service;

use OCA\ZammadBot\Service\Config;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ConfigTest extends TestCase {
	protected IAppConfig&MockObject $appConfig;
	protected Config $config;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = new Config($this->appConfig);
	}

	public function testZammadUrlLosesTrailingSlash(): void {
		$this->appConfig->expects($this->once())->method('setAppValueString')
			->with('zammad_url', 'https://zammad.example.com');
		$this->config->setZammadUrl('  https://zammad.example.com/  ');
	}

	public static function dataInvalidUrls(): array {
		return [['ftp://x'], ['zammad.example.com'], [''], ['   ']];
	}

	#[DataProvider('dataInvalidUrls')]
	public function testInvalidZammadUrlIsRejected(string $url): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setZammadUrl($url);
	}

	public function testWebhookSecretIsStoredAsSensitive(): void {
		$this->appConfig->expects($this->once())->method('setAppValueString')
			->with('webhook_secret', 'a-secret', false, true);
		$this->config->setWebhookSecret('a-secret');
	}

	public function testEmptyWebhookSecretIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setWebhookSecret('   ');
	}

	public function testTalkBotSecretIsStoredAsSensitive(): void {
		$secret = str_repeat('a', 64);
		$this->appConfig->expects($this->once())->method('setAppValueString')
			->with('talk_bot_secret', $secret, false, true);
		$this->config->setTalkBotSecret($secret);
	}

	public static function dataInvalidBotSecrets(): array {
		return [
			'too short' => [str_repeat('a', 39)],
			'too long' => [str_repeat('a', 129)],
		];
	}

	#[DataProvider('dataInvalidBotSecrets')]
	public function testTalkBotSecretLengthIsValidated(string $secret): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setTalkBotSecret($secret);
	}

	public function testZammadUrlIsNotStoredAsSensitive(): void {
		$this->appConfig->expects($this->once())->method('setAppValueString')
			->with('zammad_url', 'https://zammad.example.com');
		$this->config->setZammadUrl('https://zammad.example.com');
	}

	public function testTagRoomRoundTrip(): void {
		$this->appConfig->method('getAppValueArray')->willReturn(['team-infra' => 'room1']);
		$this->appConfig->expects($this->once())->method('setAppValueArray')
			->with('tag_rooms', ['team-infra' => 'room1', 'team-support' => 'room2']);
		$this->config->setTagRoom('Team-Support', 'room2');
	}

	public function testTagIsNormalisedOnRead(): void {
		$this->appConfig->method('getAppValueArray')->willReturn([' TEAM-Infra ' => 'room1']);
		$this->assertSame(['team-infra' => 'room1'], $this->config->getTagRooms());
	}

	public static function dataInvalidTokens(): array {
		return [['ab'], [str_repeat('a', 31)], ['Room1'], ['room-1'], ['']];
	}

	#[DataProvider('dataInvalidTokens')]
	public function testInvalidTokenIsRejected(string $token): void {
		$this->appConfig->method('getAppValueArray')->willReturn([]);
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setTagRoom('team-infra', $token);
	}

	public function testEmptyTagIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setTagRoom('   ', 'room1');
	}

	public function testRemoveUnknownTagReportsNothingRemoved(): void {
		$this->appConfig->method('getAppValueArray')->willReturn(['team-infra' => 'room1']);
		$this->appConfig->expects($this->never())->method('setAppValueArray');
		$this->assertFalse($this->config->removeTagRoom('unknown'));
	}

	public function testRemoveKnownTag(): void {
		$this->appConfig->method('getAppValueArray')->willReturn(['team-infra' => 'room1']);
		$this->appConfig->expects($this->once())->method('setAppValueArray')->with('tag_rooms', []);
		$this->assertTrue($this->config->removeTagRoom('Team-Infra'));
	}

	public function testMalformedTagMapDegradesToEmpty(): void {
		$this->appConfig->method('getAppValueArray')->willReturn(['team-infra' => ['nested'], 5 => 'room']);
		$this->assertSame([], $this->config->getTagRooms());
	}

	public function testRetentionNeverDropsBelowOneDay(): void {
		$this->appConfig->method('getAppValueInt')->willReturn(0);
		$this->assertSame(1, $this->config->getRetentionDays());
		$this->assertSame(86400, $this->config->getRetentionSeconds());
	}

	public function testRetentionMustBePositive(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setRetentionDays(0);
	}

	public function testTalkBaseUrlMayBeEmpty(): void {
		$this->appConfig->expects($this->once())->method('setAppValueString')->with('talk_base_url', '');
		$this->config->setTalkBaseUrl('');
	}

	public function testTagLeadRoundTrip(): void {
		$this->appConfig->method('getAppValueArray')->willReturn([]);
		$this->appConfig->expects($this->once())->method('setAppValueArray')
			->with('tag_leads', ['talk' => 'ada']);
		$this->config->setTagLead('Talk', 'ada');
	}

	public function testGroupLeadIsAccepted(): void {
		$this->appConfig->method('getAppValueArray')->willReturn([]);
		$this->appConfig->expects($this->once())->method('setAppValueArray')
			->with('tag_leads', ['talk' => 'group/talk-leads']);
		$this->config->setTagLead('talk', 'group/talk-leads');
	}

	public static function dataUnmentionable(): array {
		return [['not valid!'], ['a*b'], [''], ['group/'], ['"quoted"'], ['a"b']];
	}

	#[DataProvider('dataUnmentionable')]
	public function testUnmentionableLeadIsRejected(string $lead): void {
		$this->appConfig->method('getAppValueArray')->willReturn([]);
		$this->expectException(\InvalidArgumentException::class);
		$this->config->setTagLead('talk', $lead);
	}

	public function testGetTagLeadNormalisesTheTag(): void {
		$this->appConfig->method('getAppValueArray')->willReturn(['talk' => 'ada']);
		$this->assertSame('ada', $this->config->getTagLead(' TALK '));
		$this->assertSame('', $this->config->getTagLead('unknown'));
	}

	public function testRemoveTagLead(): void {
		$this->appConfig->method('getAppValueArray')->willReturn(['talk' => 'ada']);
		$this->appConfig->expects($this->once())->method('setAppValueArray')->with('tag_leads', []);
		$this->assertTrue($this->config->removeTagLead('talk'));
	}

	public function testRemoveUnknownTagLead(): void {
		$this->appConfig->method('getAppValueArray')->willReturn([]);
		$this->appConfig->expects($this->never())->method('setAppValueArray');
		$this->assertFalse($this->config->removeTagLead('talk'));
	}

	public function testDefaultEscalationSeverities(): void {
		$this->appConfig->method('getAppValueArray')->willReturnArgument(1);
		$this->assertSame(['sev1', 'sev2'], $this->config->getEscalationSeverities());
	}

	public function testEscalationSeveritiesAreNormalised(): void {
		$this->appConfig->method('getAppValueArray')->willReturn([' SEV1 ', 'sev1', 'Sev3', '']);
		$this->assertSame(['sev1', 'sev3'], $this->config->getEscalationSeverities());
	}

	public function testEscalationCanBeDisabled(): void {
		$this->appConfig->method('getAppValueArray')->willReturn([]);
		$this->assertSame([], $this->config->getEscalationSeverities());
	}
}
