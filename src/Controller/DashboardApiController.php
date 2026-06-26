<?php

namespace App\Controller;

use App\Dashboard\DashboardStore;
use App\Message\ProcessJob;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

#[Route('/api')]
final class DashboardApiController extends AbstractController
{
    private const QUEUES = ['default', 'processing', 'critical'];

    public function __construct(
        private readonly DashboardStore $store,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/state', name: 'api_state', methods: ['GET'])]
    public function state(Request $request): JsonResponse
    {
        $batchId = $request->query->get('batch');

        return new JsonResponse([
            'batchId' => $batchId,
            'stats' => $this->store->stats($batchId),
            'jobs' => $batchId ? $this->store->batchJobs($batchId) : [],
            'recentBatches' => $this->store->recentBatches(),
        ]);
    }

    #[Route('/dispatch', name: 'api_dispatch', methods: ['POST'])]
    public function dispatch(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];

        $count = max(1, min(10000, (int) ($payload['count'] ?? 0)));
        $min = max(0, (int) ($payload['min_duration'] ?? 0));
        $max = max($min, (int) ($payload['max_duration'] ?? 0));
        $queue = in_array($payload['queue'] ?? null, self::QUEUES, true) ? $payload['queue'] : 'default';
        $failChance = max(0, min(100, (int) ($payload['fail_chance'] ?? 0)));

        $batchId = (string) new Ulid();
        $ids = $this->store->insertBatch($batchId, $queue, $count, microtime(true));

        foreach ($ids as $metricId) {
            $this->bus->dispatch(new ProcessJob($metricId, random_int($min, $max), $failChance));
        }

        return new JsonResponse(['batchId' => $batchId]);
    }

    #[Route('/workers/reset', name: 'api_workers_reset', methods: ['POST'])]
    public function resetWorkers(): JsonResponse
    {
        $this->store->resetWorkers();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/reset', name: 'api_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        $this->store->reset();

        return new JsonResponse(['ok' => true]);
    }
}
