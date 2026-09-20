<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Command;

use OCA\ZammadBot\Command\Configure;
use OCA\ZammadBot\Service\Config;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

class ConfigureTest extends TestCase {
	protected Config&MockObject $config;
	protected ISecureRandom&MockObject $secureRandom;
	protected Configure $command;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('zammad_bot.Webhook.webhook')
			->willReturn('https://cloud.example.com/index.php/apps/zammad_bot/webhook');

		$this->command = new Configure($this->config, $urlGenerator, $this->secureRandom);
	}

	public function testWithoutOptionsShowsTheConfiguration(): void {
		$this->config->method('getZammadUrl')->willReturn('https://zammad.example.com');
		$this->config->method('getWebhookSecret')->willReturn('secret');
		$this->config->method('getTalkBotSecret')->willReturn('');
		$this->config->method('getRetentionDays')->willReturn(90);
		$this->config->method('getTagRooms')->willReturn([]);

		$tester = new CommandTester($this->command);
		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('https://zammad.example.com', $display);
		$this->assertStringContainsString('90 days', $display);
	}

	public function testSecretsAreNeverPrinted(): void {
		$this->config->method('getZammadUrl')->willReturn('https://zammad.example.com');
		$this->config->method('getWebhookSecret')->willReturn('super-secret-webhook-token');
		$this->config->method('getTalkBotSecret')->willReturn('super-secret-talk-token');
		$this->config->method('getRetentionDays')->willReturn(90);
		$this->config->method('getTagRooms')->willReturn([]);

		$tester = new CommandTester($this->command);
		$tester->execute([]);

		$display = $tester->getDisplay();
		$this->assertStringNotContainsString('super-secret-webhook-token', $display);
		$this->assertStringNotContainsString('super-secret-talk-token', $display);
		$this->assertStringContainsString('set', $display);
	}

	public function testAlwaysPrintsTheWebhookEndpoint(): void {
		$this->config->method('getTagRooms')->willReturn([]);
		$tester = new CommandTester($this->command);
		$tester->execute([]);
		$this->assertStringContainsString('https://cloud.example.com/index.php/apps/zammad_bot/webhook', $tester->getDisplay());
	}

	public function testSetsTheZammadUrl(): void {
		$this->config->expects($this->once())->method('setZammadUrl')->with('https://zammad.example.com');
		$tester = new CommandTester($this->command);
		$this->assertSame(0, $tester->execute(['--zammad-url' => 'https://zammad.example.com']));
	}

	public function testRejectsAnInvalidZammadUrl(): void {
		$this->config->method('setZammadUrl')->willThrowException(new \InvalidArgumentException('Zammad URL must start with http:// or https://'));
		$tester = new CommandTester($this->command);
		$this->assertSame(1, $tester->execute(['--zammad-url' => 'nope']));
		$this->assertStringContainsString('must start with', $tester->getDisplay());
	}

	public function testGeneratesAndPrintsAWebhookSecret(): void {
		$this->secureRandom->method('generate')->willReturn('generated-token');
		$this->config->expects($this->once())->method('setWebhookSecret')->with('generated-token');

		$tester = new CommandTester($this->command);
		$this->assertSame(0, $tester->execute(['--generate-webhook-secret' => true]));
		$this->assertStringContainsString('generated-token', $tester->getDisplay());
	}

	public function testAcceptsAnInlineWebhookSecret(): void {
		$this->config->expects($this->once())->method('setWebhookSecret')->with('inline-token');
		$tester = new CommandTester($this->command);
		$this->assertSame(0, $tester->execute(['--webhook-secret' => 'inline-token']));
	}

	public function testAcceptsAnInlineTalkBotSecret(): void {
		$this->config->expects($this->once())->method('setTalkBotSecret')->with('inline-talk-secret');
		$tester = new CommandTester($this->command);
		$this->assertSame(0, $tester->execute(['--talk-bot-secret' => 'inline-talk-secret']));
	}

	public function testWithoutSecretOptionsNothingIsWritten(): void {
		$this->config->method('getTagRooms')->willReturn([]);
		$this->config->expects($this->never())->method('setWebhookSecret');
		$this->config->expects($this->never())->method('setTalkBotSecret');

		$tester = new CommandTester($this->command);
		$tester->execute([]);
	}

	public function testSetsVerifyTlsAndRetention(): void {
		$this->config->expects($this->once())->method('setVerifyTls')->with(false);
		$this->config->expects($this->once())->method('setRetentionDays')->with(30);

		$tester = new CommandTester($this->command);
		$this->assertSame(0, $tester->execute(['--verify-tls' => 'false', '--retention-days' => '30']));
	}

	public function testPromptsForAHiddenWebhookSecret(): void {
		$this->config->expects($this->once())->method('setWebhookSecret')->with('typed-secret');
		// occ provides this helper set, CommandTester on its own does not.
		$this->command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));

		$tester = new CommandTester($this->command);
		$tester->setInputs(['typed-secret']);
		$this->assertSame(0, $tester->execute(['--webhook-secret' => null], ['interactive' => true]));
	}

	public function testPromptWithoutAHelperSetFailsCleanly(): void {
		$tester = new CommandTester($this->command);
		$this->assertSame(1, $tester->execute(['--webhook-secret' => null]));
		$this->assertStringContainsString('pass the value instead', $tester->getDisplay());
	}
}
