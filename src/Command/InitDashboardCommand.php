<?php

namespace App\Command;

use App\Dashboard\DashboardStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dashboard:init',
    description: 'Create the dashboard tables (job_metrics, workers) if they do not exist.',
)]
final class InitDashboardCommand extends Command
{
    public function __construct(
        private readonly DashboardStore $store,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->store->ensureSchema();

        (new SymfonyStyle($input, $output))->success('Dashboard schema is ready.');

        return Command::SUCCESS;
    }
}
