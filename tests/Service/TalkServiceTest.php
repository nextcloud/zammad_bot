<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Service;

use OCA\ZammadBot\Exception\TalkSendException;
use OCA\ZammadBot\Service\Config;
use OCA\ZammadBot\Service\TalkService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class TalkServiceTest extends TestCase {
	protected const SECRET = 'a-talk-bot-secret-that-is-long-enough-for-talk';
	protected const RANDOM = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ12';

	protected IClientService&MockObject $clientService;
	protected IClient&MockObject $client;
	protected Config&MockObject $config;
	protected TalkService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(IClient::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->clientService->method('newClient')->willReturn($this->client);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn(self::RANDOM);

		$this->config = $this->createMock(Config::class);
		$this->config->method('getTalkBotSecret')->willReturn(self::SECRET);
		$this->config->method('getTalkBaseUrl')->willReturn('');
		$this->config->method('verifyTls')->willReturn(true);

		$this->service = new TalkService($this->clientService, $urlGenerator, $secureRandom, $this->config);
	}

	protected function response(int $status): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		return $response;
	}

	public function testSuccessfulSend(): void {
		$captured = null;
		$this->client->expects($this->once())->method('post')
			->willReturnCallback(function (string $url, array $options) use (&$captured) {
				$captured = ['url' => $url, 'options' => $options];
				return $this->response(201);
			});

		$this->service->sendMessage('n3xtc10ud', 'Hello', 'ref123');

		$this->assertSame('https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/n3xtc10ud/message', $captured['url']);

		$headers = $captured['options']['headers'];
		$this->assertSame('true', $headers['OCS-APIRequest']);
		$this->assertSame(self::RANDOM, $headers['X-Nextcloud-Talk-Bot-Random']);
		$this->assertSame(
			hash_hmac('sha256', self::RANDOM . 'Hello', self::SECRET),
			$headers['X-Nextcloud-Talk-Bot-Signature'],
		);
		$this->assertGreaterThanOrEqual(32, strlen($headers['X-Nextcloud-Talk-Bot-Random']));

		// Guzzle only accepts a string body, an array would be silently wrong.
		$this->assertIsString($captured['options']['body']);
		$this->assertSame(['message' => 'Hello', 'referenceId' => 'ref123'], json_decode($captured['options']['body'], true));

		$this->assertTrue($captured['options']['nextcloud']['allow_local_address']);
		$this->assertTrue($captured['options']['verify']);
	}

	public function testSignatureCoversOnlyTheMessage(): void {
		$captured = null;
		$this->client->method('post')->willReturnCallback(function (string $url, array $options) use (&$captured) {
			$captured = $options;
			return $this->response(201);
		});

		$this->service->sendMessage('token', 'Hello', 'ignored-in-signature');
		$this->assertSame(
			hash_hmac('sha256', self::RANDOM . 'Hello', self::SECRET),
			$captured['headers']['X-Nextcloud-Talk-Bot-Signature'],
		);
	}

	public function testUnauthorizedHintsAtMissingSetup(): void {
		$this->client->method('post')->willReturn($this->response(401));
		try {
			$this->service->sendMessage('n3xtc10ud', 'Hello');
			$this->fail('Expected a TalkSendException');
		} catch (TalkSendException $e) {
			$this->assertSame(401, $e->getCode());
			$this->assertStringContainsString('talk:bot:setup', $e->getMessage());
		}
	}

	public function testPayloadTooLarge(): void {
		$this->client->method('post')->willReturn($this->response(413));
		$this->expectException(TalkSendException::class);
		$this->expectExceptionCode(413);
		$this->service->sendMessage('token', 'Hello');
	}

	public function testNotFound(): void {
		$this->client->method('post')->willReturn($this->response(404));
		$this->expectException(TalkSendException::class);
		$this->expectExceptionCode(404);
		$this->service->sendMessage('token', 'Hello');
	}

	public function testTransportErrorIsWrapped(): void {
		$original = new LocalServerException('blocked');
		$this->client->method('post')->willThrowException($original);
		try {
			$this->service->sendMessage('token', 'Hello');
			$this->fail('Expected a TalkSendException');
		} catch (TalkSendException $e) {
			$this->assertSame($original, $e->getPrevious());
		}
	}

	public function testMissingSecretSkipsTheRequestEntirely(): void {
		$config = $this->createMock(Config::class);
		$config->method('getTalkBotSecret')->willReturn('');
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');

		$service = new TalkService(
			$clientService,
			$this->createMock(IURLGenerator::class),
			$this->createMock(ISecureRandom::class),
			$config,
		);

		$this->expectException(TalkSendException::class);
		$service->sendMessage('token', 'Hello');
	}

	public function testConfiguredBaseUrlOverridesTheRequestUrl(): void {
		$config = $this->createMock(Config::class);
		$config->method('getTalkBotSecret')->willReturn(self::SECRET);
		$config->method('getTalkBaseUrl')->willReturn('http://localhost:8080');
		$config->method('verifyTls')->willReturn(false);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn(self::RANDOM);

		$captured = null;
		$this->client->method('post')->willReturnCallback(function (string $url, array $options) use (&$captured) {
			$captured = ['url' => $url, 'options' => $options];
			return $this->response(201);
		});

		$service = new TalkService($this->clientService, $this->createMock(IURLGenerator::class), $secureRandom, $config);
		$service->sendMessage('token', 'Hello');

		$this->assertSame('http://localhost:8080/ocs/v2.php/apps/spreed/api/v1/bot/token/message', $captured['url']);
		$this->assertFalse($captured['options']['verify']);
	}
}
