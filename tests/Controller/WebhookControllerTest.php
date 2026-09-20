<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Controller;

use OCA\ZammadBot\Controller\WebhookController;
use OCA\ZammadBot\Exception\InvalidPayloadException;
use OCA\ZammadBot\Service\SignatureService;
use OCA\ZammadBot\Service\WebhookService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class WebhookControllerTest extends TestCase {
	protected const BODY = '{"ticket":{"id":42}}';

	protected IRequest&MockObject $request;
	protected SignatureService&MockObject $signatureService;
	protected WebhookService&MockObject $webhookService;
	protected LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function controller(string $body = self::BODY): WebhookController&MockObject {
		$controller = $this->getMockBuilder(WebhookController::class)
			->setConstructorArgs(['zammad_bot', $this->request, $this->signatureService, $this->webhookService, $this->logger])
			->onlyMethods(['getInputStream'])
			->getMock();
		$controller->method('getInputStream')->willReturn($body);
		return $controller;
	}

	public function testInvalidSignatureIsRejectedBeforeParsing(): void {
		$this->signatureService->method('isValid')->willReturn(false);
		$this->webhookService->expects($this->never())->method('handle');

		$response = $this->controller()->webhook();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['action' => 'zammadBotWebhook'], $response->getThrottleMetadata());
	}

	public function testMissingSignatureHeaderIsRejected(): void {
		$this->request->method('getHeader')->with(SignatureService::HEADER)->willReturn('');
		$this->signatureService->method('isValid')->willReturn(false);
		$this->webhookService->expects($this->never())->method('handle');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->webhook()->getStatus());
	}

	public function testSignatureIsCheckedOverTheRawBody(): void {
		$this->request->method('getHeader')->willReturn('sha1=abc');
		$this->signatureService->expects($this->once())->method('isValid')
			->with(self::BODY, 'sha1=abc')->willReturn(true);
		$this->webhookService->method('handle')->willReturn(['sent' => 0, 'skipped' => 0, 'failed' => 0]);

		$this->controller()->webhook();
	}

	public function testHandleReceivesTheExactBytesOfTheBody(): void {
		$body = "{\"ticket\":{\"id\":42,\"title\":\"a \\u00e4 b\"}}\n";
		$this->signatureService->method('isValid')->willReturn(true);
		$this->webhookService->expects($this->once())->method('handle')
			->with($body)->willReturn(['sent' => 1, 'skipped' => 0, 'failed' => 0]);

		$this->assertSame(Http::STATUS_OK, $this->controller($body)->webhook()->getStatus());
	}

	public function testSuccessfulRun(): void {
		$this->signatureService->method('isValid')->willReturn(true);
		$this->webhookService->method('handle')->willReturn(['sent' => 2, 'skipped' => 1, 'failed' => 0]);

		$response = $this->controller()->webhook();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'ok', 'sent' => 2, 'skipped' => 1, 'failed' => 0], $response->getData());
	}

	/**
	 * Nothing to do is still a successful delivery, otherwise Zammad would
	 * mark the webhook as failing and retry.
	 */
	public function testNoMatchingTagIsStillOk(): void {
		$this->signatureService->method('isValid')->willReturn(true);
		$this->webhookService->method('handle')->willReturn(['sent' => 0, 'skipped' => 0, 'failed' => 0]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->webhook()->getStatus());
	}

	public function testInvalidPayloadReturnsBadRequest(): void {
		$this->signatureService->method('isValid')->willReturn(true);
		$this->webhookService->method('handle')->willThrowException(new InvalidPayloadException('broken'));
		$this->logger->expects($this->once())->method('warning');

		$response = $this->controller()->webhook();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['status' => 'invalid_payload'], $response->getData());
	}
}
