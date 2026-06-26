<?php

namespace App\Command;

use App\Message\ProcessJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

#[AsCommand(
    name: 'app:dispatch-jobs',
    description: 'Dispatch demo jobs onto the Laravel Cloud managed queue.',
)]
final class DispatchJobsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many jobs to dispatch', 1)
            ->addOption('duration', 'd', InputOption::VALUE_REQUIRED, 'Simulated work duration per job (ms)', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = max(1, (int) $input->getOption('count'));
        $duration = max(0, (int) $input->getOption('duration'));

        for ($i = 0; $i < $count; $i++) {
            $this->bus->dispatch(new ProcessJob((string) new Ulid(), $duration));
        }

        $io->success(sprintf('Dispatched %d job(s) onto the managed queue.', $count));

        return Command::SUCCESS;
    }
}
