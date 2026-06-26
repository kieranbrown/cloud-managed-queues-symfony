<?php

namespace Laravel\Cloud\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Carries the SQS coordinates of a received message so ack()/reject() know where
 * to report the outcome. Marked non-sendable so it is never serialized onto a
 * retry re-send (a fresh delivery gets its own coordinates).
 */
final class CloudQueueReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $receiptHandle,
        public readonly string $queueUrl,
        public readonly bool $fromAgent,
    ) {
    }
}
