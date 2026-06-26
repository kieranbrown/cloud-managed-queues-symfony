<?php

namespace App\Message;

/**
 * A demo job dispatched onto the Laravel Cloud managed queue.
 */
final class ProcessJob
{
    public function __construct(
        public readonly string $id,
        public readonly int $workDurationMs = 200,
    ) {
    }
}
