<?php

namespace Laravel\Cloud\Observability;

use Laravel\Cloud\ManagedQueueConfig;
use Laravel\Cloud\Messenger\CloudProcessingStartedStamp;
use Laravel\Cloud\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Messenger\CloudQueueTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Emits Laravel Cloud queue observability events for messages flowing through
 * the managed-queue transport, so the Cloud dashboard shows the same throughput,
 * latency and failure metrics for a Symfony app as it does for a Laravel one.
 *
 * The wire protocol matches Laravel\Foundation\Cloud\Queue + FailedJobProvider:
 *
 *   - "queued"    when a message is dispatched onto the managed queue
 *   - "started"   when a worker receives a managed-queue message
 *   - "processed" when handling succeeds
 *   - "released"  when handling fails but the message will be retried
 *   - "failed"    when handling fails terminally
 *   - "failed_job" the full payload + exception for a terminal failure
 *
 * Worker events fire for every transport, so each handler first checks for the
 * CloudQueueReceivedStamp our transport adds — that, not the (user-chosen)
 * transport name, is how we recognise our own messages.
 */
final class QueueEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Events $events,
        private readonly ManagedQueueConfig $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSend',
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onHandled',
            // Run after Messenger's retry listener (priority 100) so willRetry()
            // reflects the final retry decision: released vs. failed.
            WorkerMessageFailedEvent::class => ['onFailed', -100],
        ];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        foreach ($event->getSenders() as $sender) {
            if ($sender instanceof CloudQueueTransport) {
                $this->events->emit([
                    '_cloud_event' => 'queue',
                    'timestamp' => $this->now()->format('Y-m-d H:i:s.u'),
                    'type' => 'queued',
                    'queue' => $this->config->queue,
                ]);

                return;
            }
        }
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (! $this->isManaged($event->getEnvelope())) {
            return;
        }

        $startedAt = $this->now();

        // Stamp the start time onto the envelope so the matching handled/failed
        // event can report an accurate duration.
        $event->addStamps(new CloudProcessingStartedStamp($startedAt));

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $startedAt->format('Y-m-d H:i:s.u'),
            'type' => 'started',
            'queue' => $this->queue($event->getEnvelope()),
        ]);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (! $this->isManaged($envelope)) {
            return;
        }

        $finishedAt = $this->now();

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $finishedAt->format('Y-m-d H:i:s.u'),
            'type' => 'processed',
            'queue' => $this->queue($envelope),
            'duration_ms' => $this->durationMs($envelope, $finishedAt),
        ]);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (! $this->isManaged($envelope)) {
            return;
        }

        $finishedAt = $this->now();
        $queue = $this->queue($envelope);
        $willRetry = $event->willRetry();

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $finishedAt->format('Y-m-d H:i:s.u'),
            'type' => $willRetry ? 'released' : 'failed',
            'queue' => $queue,
            'duration_ms' => $this->durationMs($envelope, $finishedAt),
        ]);

        if ($willRetry) {
            return;
        }

        // A terminal failure is also recorded as a failed job, carrying the raw
        // payload and exception so it can be inspected and retried from the
        // Laravel Cloud dashboard.
        $startedAt = $this->startedAt($envelope) ?? $finishedAt;
        $received = $envelope->last(CloudQueueReceivedStamp::class);

        $this->events->emit([
            '_cloud_event' => 'failed_job',
            'id' => (string) Uuid::v7(),
            'queue' => $queue,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'attempts' => RedeliveryStamp::getRetryCountFromEnvelope($envelope) + 1,
            'payload' => $received?->body ?? '',
            'exception' => (string) mb_convert_encoding((string) $event->getThrowable(), 'UTF-8'),
        ]);
    }

    private function isManaged(Envelope $envelope): bool
    {
        return $envelope->last(CloudQueueReceivedStamp::class) !== null;
    }

    private function queue(Envelope $envelope): string
    {
        $stamp = $envelope->last(CloudQueueReceivedStamp::class);

        return $stamp !== null
            ? $this->config->normalizeQueue($stamp->queueUrl)
            : $this->config->queue;
    }

    private function startedAt(Envelope $envelope): ?\DateTimeImmutable
    {
        return $envelope->last(CloudProcessingStartedStamp::class)?->startedAt;
    }

    private function durationMs(Envelope $envelope, \DateTimeImmutable $finishedAt): int
    {
        $startedAt = $this->startedAt($envelope);

        if ($startedAt === null) {
            return 0;
        }

        return (int) max(0, round(((float) $finishedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
