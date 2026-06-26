<?php

namespace App\MessageHandler;

use App\Message\ProcessJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessJobHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessJob $job): void
    {
        $this->logger->info('Processing managed-queue job', [
            'id' => $job->id,
            'work_duration_ms' => $job->workDurationMs,
        ]);

        if ($job->workDurationMs > 0) {
            usleep($job->workDurationMs * 1000);
        }
    }
}
