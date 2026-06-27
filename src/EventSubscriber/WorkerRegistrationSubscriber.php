<?php

namespace App\EventSubscriber;

use App\Dashboard\DashboardStore;
use App\Dashboard\WorkerIdentity;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Registers a queue worker in the dashboard for its whole lifetime: one row on
 * boot, removed when it stops. Mirrors the original demo's "standard worker"
 * heartbeat (register on start, deregister on stop).
 *
 * Only workers consuming the managed-queue transport are tracked.
 */
final class WorkerRegistrationSubscriber
{
    private const TRANSPORT = 'async';

    public function __construct(
        private readonly DashboardStore $store,
        private readonly ManagedQueueConfig $config,
    ) {
    }

    #[AsEventListener]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if (! $this->consumesManagedQueue($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->store->registerWorker(WorkerIdentity::current(), $this->config->queue);
    }

    #[AsEventListener]
    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if (! $this->consumesManagedQueue($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->store->deregisterWorker(WorkerIdentity::current());
    }

    /**
     * @param array<int, string> $transportNames
     */
    private function consumesManagedQueue(array $transportNames): bool
    {
        return $transportNames === [] || in_array(self::TRANSPORT, $transportNames, true);
    }
}
