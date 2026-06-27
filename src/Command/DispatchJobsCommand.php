<?php

namespace App\Command;

use App\Dashboard\DashboardStore;
use App\Message\ProcessJob;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Dispatch a batch of demo jobs from the terminal — the CLI equivalent of the
 * dashboard's "Dispatch" button. Handy for firing large batches (e.g. to watch
 * Laravel Cloud autoscale workers) without a browser.
 *
 * Mirrors the dashboard API: insert one job_metrics row per job first, then
 * dispatch a ProcessJob carrying each row id so workers can record timings
 * against a real row.
 */
#[AsCommand(
    name: 'app:dispatch-jobs',
    description: 'Dispatch demo jobs onto the Laravel Cloud managed queue.',
)]
final class DispatchJobsCommand extends Command
{
    private const QUEUES = ['default', 'processing', 'critical'];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly DashboardStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many jobs to dispatch', 1)
            ->addOption('duration', 'd', InputOption::VALUE_REQUIRED, 'Simulated work duration per job (ms)', 200)
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Queue to dispatch to ('.implode(', ', self::QUEUES).')', 'default')
            ->addOption('fail-chance', null, InputOption::VALUE_REQUIRED, 'Probability (0-100) each job fails on purpose', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = max(1, (int) $input->getOption('count'));
        $duration = max(0, (int) $input->getOption('duration'));
        $failChance = max(0, min(100, (int) $input->getOption('fail-chance')));

        $queue = (string) $input->getOption('queue');
        if (! in_array($queue, self::QUEUES, true)) {
            $io->error(sprintf('Invalid queue "%s". Choose one of: %s.', $queue, implode(', ', self::QUEUES)));

            return Command::INVALID;
        }

        // Insert the metric rows first, then dispatch a job per row id so the
        // worker has a real job_metrics row to stamp pickup/completion onto.
        $batchId = (string) new Ulid();
        $ids = $this->store->insertBatch($batchId, $queue, $count, microtime(true));

        foreach ($ids as $metricId) {
            // Route to the chosen managed queue (the ->onQueue() equivalent),
            // matching the queue name recorded on each metric row above.
            $this->bus->dispatch(
                new ProcessJob($metricId, $duration, $failChance),
                [new CloudQueueStamp($queue)],
            );
        }

        $io->success(sprintf('Dispatched %d job(s) onto the "%s" queue (batch %s).', $count, $queue, $batchId));

        return Command::SUCCESS;
    }
}
