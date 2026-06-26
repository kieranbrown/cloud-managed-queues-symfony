<?php

namespace App\Message;

/**
 * A demo job dispatched onto the Laravel Cloud managed queue. Carries the id of
 * its job_metrics row so the worker can record its pickup/completion timings.
 */
final class ProcessJob
{
    public function __construct(
        public readonly int $metricId,
        public readonly int $workDurationMs = 200,
        /**
         * Probability (0-100) that the handler throws instead of completing.
         * Used to exercise the failure / retry path and the resulting Laravel
         * Cloud "released" and "failed" observability events.
         */
        public readonly int $failChance = 0,
    ) {
    }
}
