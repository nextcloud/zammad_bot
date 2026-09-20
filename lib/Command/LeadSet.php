<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Command;

use OCA\ZammadBot\Service\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LeadSet extends Command {
	public function __construct(
		protected Config $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('zammad_bot:lead:set')
			->setDescription('Set the lead mentioned when a severe ticket carries this tag')
			->addArgument('tag', InputArgument::REQUIRED, 'The Zammad tag, e.g. talk')
			->addArgument('lead', InputArgument::REQUIRED, 'A Nextcloud user id, or group/<id> to mention a whole group');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$lead = (string)$input->getArgument('lead');
		try {
			$this->config->setTagLead((string)$input->getArgument('tag'), $lead);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln('<info>Lead saved, they will be mentioned as @"' . $lead . '"</info>');
		$output->writeln('Mentioned for severities: ' . implode(', ', $this->config->getEscalationSeverities()));
		return 0;
	}
}
