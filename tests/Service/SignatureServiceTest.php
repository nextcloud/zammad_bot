<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Service;

use OCA\ZammadBot\Service\Config;
use OCA\ZammadBot\Service\SignatureService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SignatureServiceTest extends TestCase {
	protected const SECRET = 's3cr3t-webhook-token';
	protected const BODY = '{"ticket":{"id":42}}';

	protected Config&MockObject $config;
	protected LoggerInterface&MockObject $logger;
	protected SignatureService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(Config::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SignatureService($this->config, $this->logger);
	}

	protected function header(string $body = self::BODY, string $secret = self::SECRET): string {
		return 'sha1=' . hash_hmac('sha1', $body, $secret);
	}

	public function testValidSignature(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertTrue($this->service->isValid(self::BODY, $this->header()));
	}

	public function testUppercaseHexIsAccepted(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertTrue($this->service->isValid(self::BODY, strtoupper($this->header())));
	}

	public function testEmptyBodySignedCorrectly(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertTrue($this->service->isValid('', $this->header('')));
	}

	public function testTamperedBodyIsRejected(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertFalse($this->service->isValid('{"ticket":{"id":43}}', $this->header()));
	}

	public function testWrongSecretIsRejected(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertFalse($this->service->isValid(self::BODY, $this->header(self::BODY, 'other-secret')));
	}

	public function testMissingHeaderIsRejected(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertFalse($this->service->isValid(self::BODY, ''));
	}

	public function testHeaderWithoutPrefixIsRejected(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertFalse($this->service->isValid(self::BODY, hash_hmac('sha1', self::BODY, self::SECRET)));
	}

	public function testHeaderWithWrongAlgorithmPrefixIsRejected(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertFalse($this->service->isValid(self::BODY, 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET)));
	}

	/**
	 * An unconfigured secret must never be read as "no verification needed",
	 * that would leave the public endpoint wide open.
	 */
	public function testUnconfiguredSecretFailsClosed(): void {
		$this->config->method('getWebhookSecret')->willReturn('');
		$this->logger->expects($this->once())->method('error');
		$this->assertFalse($this->service->isValid(self::BODY, $this->header()));
	}

	public function testSignProducesAnAcceptedHeader(): void {
		$this->config->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->assertTrue($this->service->isValid(self::BODY, $this->service->sign(self::BODY, self::SECRET)));
	}
}
