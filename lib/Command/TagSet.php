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

class TagSet extends Command {
	public function __construct(
		protected Config $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('zammad_bot:tag:set')
			->setDescription('Map a Zammad tag to a Talk conversation')
			->addArgument('tag', InputArgument::REQUIRED, 'The Zammad tag, e.g. team-infra')
			->addArgument('token', InputArgument::REQUIRED, 'The token of the Talk conversation');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->config->setTagRoom((string)$input->getArgument('tag'), (string)$input->getArgument('token'));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln('<info>Mapping saved</info>');
		$output->writeln('Remember to run: occ talk:bot:setup <bot-id> ' . $input->getArgument('token'));
		return 0;
	}
}
