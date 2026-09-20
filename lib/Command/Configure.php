<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Command;

use OCA\ZammadBot\Service\Config;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException as SymfonyLogicException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class Configure extends Command {
	private const GENERATED_SECRET_LENGTH = 64;

	public function __construct(
		protected Config $config,
		protected IURLGenerator $urlGenerator,
		protected ISecureRandom $secureRandom,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('zammad_bot:configure')
			->setDescription('Configure the connection to Zammad and Talk')
			->addOption('zammad-url', null, InputOption::VALUE_REQUIRED, 'Base URL of the Zammad installation')
			->addOption('webhook-secret', null, InputOption::VALUE_OPTIONAL, 'HMAC token shared with the Zammad webhook, omit the value to be prompted', false)
			->addOption('generate-webhook-secret', null, InputOption::VALUE_NONE, 'Generate, store and print a random webhook token')
			->addOption('talk-bot-secret', null, InputOption::VALUE_OPTIONAL, 'Secret printed by occ talk:bot:create, omit the value to be prompted', false)
			->addOption('talk-base-url', null, InputOption::VALUE_REQUIRED, 'Override the URL used to reach this server\'s own Talk API')
			->addOption('verify-tls', null, InputOption::VALUE_REQUIRED, 'Whether to verify TLS when calling the local Talk API (true/false)')
			->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'How long a ticket stays deduplicated, in days');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$changed = false;

		try {
			$changed = $this->applyOptions($input, $output);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		if (!$changed) {
			$this->showConfiguration($output);
		}

		$output->writeln('');
		$output->writeln('Webhook endpoint for Zammad:');
		$output->writeln('<info>' . $this->urlGenerator->linkToRouteAbsolute('zammad_bot.Webhook.webhook') . '</info>');
		return 0;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	protected function applyOptions(InputInterface $input, OutputInterface $output): bool {
		$changed = false;

		$zammadUrl = $input->getOption('zammad-url');
		if ($zammadUrl !== null) {
			$this->config->setZammadUrl((string)$zammadUrl);
			$output->writeln('<info>Zammad URL saved</info>');
			$changed = true;
		}

		if ($input->getOption('generate-webhook-secret')) {
			$secret = $this->secureRandom->generate(self::GENERATED_SECRET_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->config->setWebhookSecret($secret);
			$output->writeln('<info>Webhook secret generated, enter it in the Zammad webhook:</info>');
			$output->writeln($secret);
			$changed = true;
		} elseif (($secret = $this->readSecret($input, $output, 'webhook-secret', 'Zammad webhook secret: ')) !== null) {
			$this->config->setWebhookSecret($secret);
			$output->writeln('<info>Webhook secret saved</info>');
			$changed = true;
		}

		if (($secret = $this->readSecret($input, $output, 'talk-bot-secret', 'Talk bot secret: ')) !== null) {
			$this->config->setTalkBotSecret($secret);
			$output->writeln('<info>Talk bot secret saved</info>');
			$changed = true;
		}

		$talkBaseUrl = $input->getOption('talk-base-url');
		if ($talkBaseUrl !== null) {
			$this->config->setTalkBaseUrl((string)$talkBaseUrl);
			$output->writeln('<info>Talk base URL saved</info>');
			$changed = true;
		}

		$verifyTls = $input->getOption('verify-tls');
		if ($verifyTls !== null) {
			$this->config->setVerifyTls(filter_var($verifyTls, FILTER_VALIDATE_BOOLEAN));
			$output->writeln('<info>TLS verification saved</info>');
			$changed = true;
		}

		$retentionDays = $input->getOption('retention-days');
		if ($retentionDays !== null) {
			$this->config->setRetentionDays((int)$retentionDays);
			$output->writeln('<info>Retention saved</info>');
			$changed = true;
		}

		return $changed;
	}

	/**
	 * An option declared with VALUE_OPTIONAL and a default of false is `false`
	 * when it was not given at all and `null` when it was given without a
	 * value, which is when the secret is prompted for instead.
	 */
	protected function readSecret(InputInterface $input, OutputInterface $output, string $option, string $prompt): ?string {
		$value = $input->getOption($option);
		if ($value === false) {
			return null;
		}
		if ($value !== null) {
			return (string)$value;
		}

		try {
			$helper = $this->getHelper('question');
		} catch (SymfonyLogicException $e) {
			throw new \InvalidArgumentException('Cannot prompt for ' . $option . ', pass the value instead', 0, $e);
		}
		if (!$helper instanceof QuestionHelper) {
			throw new \InvalidArgumentException('Cannot prompt for ' . $option . ', pass the value instead');
		}

		$question = new Question($prompt);
		$question->setHidden(true);
		$question->setHiddenFallback(false);
		return (string)$helper->ask($input, $output, $question);
	}

	protected function showConfiguration(OutputInterface $output): void {
		$output->writeln('Zammad URL:      ' . ($this->config->getZammadUrl() ?: '<comment>not set</comment>'));
		$output->writeln('Webhook secret:  ' . ($this->config->getWebhookSecret() !== '' ? '<info>set</info>' : '<comment>not set</comment>'));
		$output->writeln('Talk bot secret: ' . ($this->config->getTalkBotSecret() !== '' ? '<info>set</info>' : '<comment>not set</comment>'));
		$output->writeln('Talk base URL:   ' . ($this->config->getTalkBaseUrl() ?: 'derived from the request'));
		$output->writeln('Verify TLS:      ' . ($this->config->verifyTls() ? 'yes' : 'no'));
		$output->writeln('Retention:       ' . $this->config->getRetentionDays() . ' days');
		$output->writeln('Mapped tags:     ' . (count($this->config->getTagRooms()) ?: 'none'));
	}
}
