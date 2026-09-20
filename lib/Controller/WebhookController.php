<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Controller;

use OCA\ZammadBot\Exception\InvalidPayloadException;
use OCA\ZammadBot\Service\SignatureService;
use OCA\ZammadBot\Service\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		protected SignatureService $signatureService,
		protected WebhookService $webhookService,
		protected LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return the raw body of the request. This can be overridden in tests.
	 */
	protected function getInputStream(): string {
		return (string)file_get_contents('php://input');
	}

	/**
	 * Receive a ticket from a Zammad webhook.
	 *
	 * Takes no parameters on purpose: the body is read raw so the signature can
	 * be checked over the exact bytes Zammad signed.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'zammadBotWebhook')]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/webhook')]
	public function webhook(): JSONResponse {
		$body = $this->getInputStream();

		// Authenticate before the payload is read in any way.
		if (!$this->signatureService->isValid($body, $this->request->getHeader(SignatureService::HEADER))) {
			$this->logger->warning('Rejected a Zammad webhook with an invalid signature');
			$response = new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'zammadBotWebhook']);
			return $response;
		}

		try {
			$result = $this->webhookService->handle($body);
		} catch (InvalidPayloadException $e) {
			$this->logger->warning('Could not read the Zammad webhook payload', ['exception' => $e]);
			return new JSONResponse(['status' => 'invalid_payload'], Http::STATUS_BAD_REQUEST);
		}

		// A ticket without a configured tag is still a successful delivery,
		// otherwise Zammad would flag the webhook as failing and retry.
		return new JSONResponse(['status' => 'ok'] + $result);
	}
}
