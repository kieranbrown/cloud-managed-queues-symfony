<?php

namespace Laravel\Cloud\Tests;

use Aws\Sqs\SqsClient;
use Laravel\Cloud\Agent\AgentClient;
use Laravel\Cloud\ManagedQueueConfig;
use Laravel\Cloud\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Messenger\CloudQueueTransport;
use Laravel\Cloud\Observability\Events;
use Laravel\Cloud\Observability\QueueEventSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Verifies the subscriber emits the same cloud event protocol Laravel does, by
 * capturing the newline-delimited JSON over a real local unix socket.
 */
class QueueEventSubscriberTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.us-east-2.amazonaws.com/000000000000/default-env-x';

    private string $socketPath = '';

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->socketPath = sys_get_temp_dir().'/laravel-cloud-events-'.bin2hex(random_bytes(4)).'.sock';

        $server = @stream_socket_server('unix://'.$this->socketPath, $errno, $errstr);

        if ($server === false) {
            $this->markTestSkipped("Unable to bind a unix socket: {$errstr} ({$errno})");
        }

        $this->server = $server;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        @unlink($this->socketPath);
    }

    public function testItEmitsAQueuedEventWhenSendingToTheManagedTransport(): void
    {
        $subscriber = $this->subscriber();

        $event = new SendMessageToTransportsEvent(
            new Envelope(new DummyMessage('hi')),
            ['cloud' => $this->cloudTransport()],
        );

        $lines = $this->capture(fn () => $subscriber->onSend($event));

        $this->assertCount(1, $lines);
        $this->assertSame('queue', $lines[0]['_cloud_event']);
        $this->assertSame('queued', $lines[0]['type']);
        $this->assertSame('default', $lines[0]['queue']);
        $this->assertNotEmpty($lines[0]['timestamp']);
    }

    public function testItIgnoresSendsToOtherTransports(): void
    {
        $subscriber = $this->subscriber();

        $other = new class {};
        $event = new SendMessageToTransportsEvent(new Envelope(new DummyMessage('hi')), ['other' => $other]);

        $this->assertSame([], $this->capture(fn () => $subscriber->onSend($event)));
    }

    public function testItEmitsStartedThenProcessedWithADuration(): void
    {
        $subscriber = $this->subscriber();

        $received = new WorkerMessageReceivedEvent($this->managedEnvelope(), 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            // The worker carries the envelope (now stamped with the start time)
            // forward to the handled event.
            usleep(2000);
            $subscriber->onHandled(new WorkerMessageHandledEvent($received->getEnvelope(), 'cloud'));
        });

        $this->assertCount(2, $lines);

        $this->assertSame('started', $lines[0]['type']);
        $this->assertSame('default', $lines[0]['queue']);
        $this->assertArrayNotHasKey('duration_ms', $lines[0]);

        $this->assertSame('processed', $lines[1]['type']);
        $this->assertSame('default', $lines[1]['queue']);
        $this->assertGreaterThanOrEqual(0, $lines[1]['duration_ms']);
    }

    public function testATerminalFailureEmitsFailedAndAFailedJob(): void
    {
        $subscriber = $this->subscriber();

        $received = new WorkerMessageReceivedEvent($this->managedEnvelope(), 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            $failed = new WorkerMessageFailedEvent(
                $received->getEnvelope(),
                'cloud',
                new \RuntimeException('boom'),
            );
            // willRetry() defaults to false: a terminal failure.
            $subscriber->onFailed($failed);
        });

        $this->assertCount(3, $lines);
        $this->assertSame('started', $lines[0]['type']);

        $this->assertSame('queue', $lines[1]['_cloud_event']);
        $this->assertSame('failed', $lines[1]['type']);

        $this->assertSame('failed_job', $lines[2]['_cloud_event']);
        $this->assertSame('default', $lines[2]['queue']);
        $this->assertSame(1, $lines[2]['attempts']);
        $this->assertSame('raw-body', $lines[2]['payload']);
        $this->assertStringContainsString('boom', $lines[2]['exception']);
        $this->assertNotEmpty($lines[2]['id']);
        $this->assertNotEmpty($lines[2]['started_at']);
    }

    public function testItTrimsAFailedJobUnderTheCriLineLimit(): void
    {
        $subscriber = $this->subscriber();

        // A realistic oversized failed job: a wrapper body with a huge
        // base64-ish blob, plus a deep exception trace.
        $body = json_encode([
            'uuid' => 'job-uuid-1',
            'displayName' => 'App\\Message\\ProcessJob',
            'body' => str_repeat('A', 12000),
            'headers' => ['type' => 'App\\Message\\ProcessJob'],
        ]);

        $envelope = (new Envelope(new DummyMessage('big')))
            ->with(new CloudQueueReceivedStamp('m-big', 'rh', self::QUEUE_URL, fromAgent: true, body: $body));
        $received = new WorkerMessageReceivedEvent($envelope, 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);
            $subscriber->onFailed(new WorkerMessageFailedEvent(
                $received->getEnvelope(),
                'cloud',
                new \RuntimeException(str_repeat('deep trace ', 2000)),
            ));
        });

        // lines: [0]=started, [1]=failed (queue), [2]=failed_job
        $failedJob = $lines[2];
        $this->assertSame('failed_job', $failedJob['_cloud_event']);

        // The whole event must serialize to well under containerd's 16 KiB
        // CRI line split, so it survives the logging collector intact.
        $this->assertLessThan(16384, strlen(json_encode($failedJob)));

        // Identifying metadata survives so the dashboard can still name the job.
        $payload = json_decode($failedJob['payload'], true);
        $this->assertSame('App\\Message\\ProcessJob', $payload['displayName']);
        $this->assertSame('job-uuid-1', $payload['uuid']);
        $this->assertTrue($payload['body_truncated']);
        $this->assertLessThanOrEqual(2100, strlen($payload['body']));

        // The exception is capped and marked.
        $this->assertLessThanOrEqual(4100, strlen($failedJob['exception']));
        $this->assertStringContainsString('truncated', $failedJob['exception']);
    }

    public function testARetryableFailureEmitsReleasedAndNoFailedJob(): void
    {
        $subscriber = $this->subscriber();

        $envelope = $this->managedEnvelope()->with(new RedeliveryStamp(1));
        $received = new WorkerMessageReceivedEvent($envelope, 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            $failed = new WorkerMessageFailedEvent($received->getEnvelope(), 'cloud', new \RuntimeException('again'));
            $failed->setForRetry();
            $subscriber->onFailed($failed);
        });

        $this->assertCount(2, $lines);
        $this->assertSame('started', $lines[0]['type']);
        $this->assertSame('released', $lines[1]['type']);
    }

    public function testItIgnoresWorkerEventsForMessagesFromOtherTransports(): void
    {
        $subscriber = $this->subscriber();

        $event = new WorkerMessageReceivedEvent(new Envelope(new DummyMessage('x')), 'other');

        $this->assertSame([], $this->capture(fn () => $subscriber->onReceived($event)));
    }

    private function subscriber(): QueueEventSubscriber
    {
        return new QueueEventSubscriber(new Events('unix://'.$this->socketPath), $this->config());
    }

    private function managedEnvelope(): Envelope
    {
        return (new Envelope(new DummyMessage('work')))
            ->with(new CloudQueueReceivedStamp('m-1', 'rh-1', self::QUEUE_URL, fromAgent: true, body: 'raw-body'));
    }

    private function config(): ManagedQueueConfig
    {
        return ManagedQueueConfig::fromEnvironment(
            json_encode(['connection' => [
                'prefix' => 'https://sqs.us-east-2.amazonaws.com/000000000000',
                'suffix' => '-env-x',
                'queue' => 'default',
                'region' => 'us-east-2',
            ]]),
            '/unused.sock',
        );
    }

    private function cloudTransport(): CloudQueueTransport
    {
        $sqs = new SqsClient([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        return new CloudQueueTransport($sqs, new AgentClient('/unused.sock'), $this->config(), new PhpSerializer(), false);
    }

    /**
     * Run the emitting closure and return the decoded JSON lines the socket
     * server received.
     *
     * @return list<array<string, mixed>>
     */
    private function capture(callable $emit): array
    {
        $emit();

        $client = @stream_socket_accept($this->server, 2);

        if ($client === false) {
            return [];
        }

        stream_set_blocking($client, false);

        $buffer = '';
        $deadline = microtime(true) + 1;

        while (microtime(true) < $deadline) {
            $chunk = fread($client, 8192);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                // Keep reading briefly in case more lines are still in flight.
                $deadline = microtime(true) + 0.1;
            } else {
                usleep(5000);
            }
        }

        fclose($client);

        $lines = [];

        foreach (explode("\n", trim($buffer)) as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true);
            }
        }

        return $lines;
    }
}
