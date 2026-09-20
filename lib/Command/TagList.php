<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Command;

use OCA\ZammadBot\Service\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TagList extends Command {
	public function __construct(
		protected Config $config,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('zammad_bot:tag:list')
			->setDescription('List the configured Zammad tag to Talk conversation mappings');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$map = $this->config->getTagRooms();
		if ($map === []) {
			$output->writeln('<comment>No mappings configured, use occ zammad_bot:tag:set</comment>');
			return 0;
		}

		$table = new Table($output);
		$table->setHeaders(['Zammad tag', 'Conversation token']);
		foreach ($map as $tag => $token) {
			$table->addRow([$tag, $token]);
		}
		$table->render();
		return 0;
	}
}
