<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Command;

use OCA\ZammadBot\Command\TagList;
use OCA\ZammadBot\Command\TagRemove;
use OCA\ZammadBot\Command\TagSet;
use OCA\ZammadBot\Service\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

class TagCommandsTest extends TestCase {
	protected Config&MockObject $config;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
	}

	public function testTagSetStoresTheMapping(): void {
		$this->config->expects($this->once())->method('setTagRoom')->with('team-infra', 'n3xtc10ud');

		$tester = new CommandTester(new TagSet($this->config));
		$this->assertSame(0, $tester->execute(['tag' => 'team-infra', 'token' => 'n3xtc10ud']));
		$this->assertStringContainsString('Mapping saved', $tester->getDisplay());
	}

	public function testTagSetRemindsAboutTheTalkSetup(): void {
		$tester = new CommandTester(new TagSet($this->config));
		$tester->execute(['tag' => 'team-infra', 'token' => 'n3xtc10ud']);
		$this->assertStringContainsString('talk:bot:setup', $tester->getDisplay());
	}

	public function testTagSetReportsAnInvalidToken(): void {
		$this->config->method('setTagRoom')->willThrowException(new \InvalidArgumentException('"BAD" is not a valid conversation token'));

		$tester = new CommandTester(new TagSet($this->config));
		$this->assertSame(1, $tester->execute(['tag' => 'team-infra', 'token' => 'BAD']));
		$this->assertStringContainsString('not a valid conversation token', $tester->getDisplay());
	}

	public function testTagRemoveKnownTag(): void {
		$this->config->method('removeTagRoom')->with('team-infra')->willReturn(true);

		$tester = new CommandTester(new TagRemove($this->config));
		$this->assertSame(0, $tester->execute(['tag' => 'team-infra']));
		$this->assertStringContainsString('Mapping removed', $tester->getDisplay());
	}

	public function testTagRemoveUnknownTagFails(): void {
		$this->config->method('removeTagRoom')->willReturn(false);

		$tester = new CommandTester(new TagRemove($this->config));
		$this->assertSame(1, $tester->execute(['tag' => 'nope']));
		$this->assertStringContainsString('No mapping found', $tester->getDisplay());
	}

	public function testTagListShowsTheMappings(): void {
		$this->config->method('getTagRooms')->willReturn(['team-infra' => 'room1', 'team-support' => 'room2']);

		$tester = new CommandTester(new TagList($this->config));
		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('team-infra', $display);
		$this->assertStringContainsString('room2', $display);
	}

	public function testTagListWithoutMappings(): void {
		$this->config->method('getTagRooms')->willReturn([]);

		$tester = new CommandTester(new TagList($this->config));
		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('No mappings configured', $tester->getDisplay());
	}
}
