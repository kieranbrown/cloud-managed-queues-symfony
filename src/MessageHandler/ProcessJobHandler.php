<?php

namespace App\MessageHandler;

use App\Dashboard\DashboardStore;
use App\Dashboard\WorkerIdentity;
use App\Message\ProcessJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessJobHandler
{
    public function __construct(
        private readonly DashboardStore $store,
    ) {
    }

    public function __invoke(ProcessJob $job): void
    {
        // Record pickup the moment the worker starts the job, tagged with the
        // host:pid that ran it so the dashboard can colour the waterfall and
        // count concurrent workers.
        $this->store->recordPickup($job->metricId, WorkerIdentity::current(), microtime(true));

        if ($job->workDurationMs > 0) {
            usleep($job->workDurationMs * 1000);
        }

        $this->store->recordCompletion($job->metricId, microtime(true));
    }
}
