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

class LeadRemove extends Command {
	public function __construct(
		protected Config $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('zammad_bot:lead:remove')
			->setDescription('Stop mentioning a lead for this tag')
			->addArgument('tag', InputArgument::REQUIRED, 'The Zammad tag');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->removeTagLead((string)$input->getArgument('tag'))) {
			$output->writeln('<comment>No lead configured for this tag</comment>');
			return 1;
		}

		$output->writeln('<info>Lead removed</info>');
		return 0;
	}
}
