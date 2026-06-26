<?php

namespace App\Dashboard;

/**
 * Identifies a queue worker the same way the original demo did: host:pid.
 */
final class WorkerIdentity
{
    public static function current(): string
    {
        return gethostname().':'.getmypid();
    }
}
