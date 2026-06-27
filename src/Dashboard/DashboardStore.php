<?php

namespace App\Dashboard;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * All persistence for the queue dashboard, backed by Doctrine DBAL so the
 * timing aggregations can be expressed as plain SQL (mirroring the original
 * Laravel demo). Two tables:
 *
 *  - job_metrics: one row per dispatched job, tracking the dispatch / pickup /
 *    completion timestamps (microtime floats) and the worker that ran it.
 *  - workers: the set of currently-running queue workers.
 */
final class DashboardStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Create the dashboard tables if they do not already exist. Safe to run on
     * every deploy.
     */
    public function ensureSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $platform = $this->connection->getDatabasePlatform();

        if (! $schemaManager->tablesExist(['job_metrics'])) {
            $metrics = new Table('job_metrics');
            $metrics->addColumn('id', 'integer', ['autoincrement' => true]);
            $metrics->addColumn('batch_id', 'string', ['length' => 40]);
            $metrics->addColumn('queue', 'string', ['length' => 40, 'default' => 'default']);
            $metrics->addColumn('job_number', 'integer');
            $metrics->addColumn('dispatched_at', 'float');
            $metrics->addColumn('picked_up_at', 'float', ['notnull' => false]);
            $metrics->addColumn('completed_at', 'float', ['notnull' => false]);
            $metrics->addColumn('worker_id', 'string', ['length' => 255, 'notnull' => false]);
            $metrics->addColumn('failed', 'boolean', ['default' => false]);
            $metrics->setPrimaryKey(['id']);
            $metrics->addIndex(['batch_id']);

            foreach ($platform->getCreateTableSQL($metrics) as $sql) {
                $this->connection->executeStatement($sql);
            }
        }

        if (! $schemaManager->tablesExist(['workers'])) {
            $workers = new Table('workers');
            $workers->addColumn('worker_id', 'string', ['length' => 255]);
            $workers->addColumn('queue', 'string', ['length' => 120, 'notnull' => false]);
            $workers->addColumn('started_at', 'datetime_immutable');
            $workers->setPrimaryKey(['worker_id']);

            foreach ($platform->getCreateTableSQL($workers) as $sql) {
                $this->connection->executeStatement($sql);
            }
        }
    }

    /**
     * Insert one metric row per job in the batch and return their ids, paired
     * with their 1-based job number.
     *
     * @return array<int, int> job_number => metric id
     */
    public function insertBatch(string $batchId, string $queue, int $count, float $dispatchedAt): array
    {
        $this->connection->transactional(function (Connection $connection) use ($batchId, $queue, $count, $dispatchedAt): void {
            for ($number = 1; $number <= $count; $number++) {
                $connection->insert('job_metrics', [
                    'batch_id' => $batchId,
                    'queue' => $queue,
                    'job_number' => $number,
                    'dispatched_at' => $dispatchedAt,
                ]);
            }
        });

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, job_number FROM job_metrics WHERE batch_id = ? ORDER BY job_number',
            [$batchId],
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['job_number']] = (int) $row['id'];
        }

        return $ids;
    }

    public function recordPickup(int $metricId, string $workerId, float $at): void
    {
        $this->connection->update('job_metrics', [
            'picked_up_at' => $at,
            'worker_id' => $workerId,
        ], ['id' => $metricId]);
    }

    public function recordCompletion(int $metricId, float $at): void
    {
        // Clear any failure flag too: a job that ultimately succeeds on a retry
        // should no longer count as failed.
        //
        // The boolean type is explicit: without it DBAL binds the column as a
        // string, so `false` becomes '' — which Postgres rejects for a boolean
        // column, leaving the row stuck without a completed_at ("active").
        $this->connection->update(
            'job_metrics',
            ['completed_at' => $at, 'failed' => false],
            ['id' => $metricId],
            ['failed' => Types::BOOLEAN],
        );
    }

    public function recordFailure(int $metricId, float $at): void
    {
        $this->connection->update(
            'job_metrics',
            ['completed_at' => $at, 'failed' => true],
            ['id' => $metricId],
            ['failed' => Types::BOOLEAN],
        );
    }

    public function registerWorker(string $workerId, ?string $queue): void
    {
        // Upsert by hand so re-registration (e.g. a restarted worker reusing the
        // same host:pid) doesn't trip the primary key.
        $this->connection->delete('workers', ['worker_id' => $workerId]);
        $this->connection->insert('workers', [
            'worker_id' => $workerId,
            'queue' => $queue,
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function deregisterWorker(string $workerId): void
    {
        $this->connection->delete('workers', ['worker_id' => $workerId]);
    }

    public function resetWorkers(): void
    {
        $this->connection->executeStatement('DELETE FROM workers');
    }

    public function reset(): void
    {
        $this->connection->executeStatement('DELETE FROM job_metrics');
    }

    public function countWorkers(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM workers');
    }

    /**
     * Active worker counts grouped into the three demo queues.
     *
     * @return array<string, int>
     */
    public function workersByQueue(): array
    {
        $byQueue = ['default' => 0, 'processing' => 0, 'critical' => 0];

        $rows = $this->connection->fetchAllAssociative(
            'SELECT queue, count(*) as count FROM workers GROUP BY queue',
        );

        foreach ($rows as $row) {
            foreach (explode(',', (string) ($row['queue'] ?? 'default')) as $name) {
                $name = trim($name) ?: 'default';

                if (array_key_exists($name, $byQueue)) {
                    $byQueue[$name] += (int) $row['count'];
                }
            }
        }

        return $byQueue;
    }

    /**
     * @return array{
     *     total: int, pending: int, processing: int, completed: int, failed: int,
     *     avgWaitMs: float|null, avgProcessMs: float|null, totalDurationMs: float|null,
     *     activeWorkers: int, workersByQueue: array<string,int>, peakWorkers: int,
     *     uniqueWorkers: int, jobsPerSecond: float|null
     * }
     */
    public function stats(?string $batchId): array
    {
        $base = [
            'total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0,
            'avgWaitMs' => null, 'avgProcessMs' => null, 'totalDurationMs' => null,
            'activeWorkers' => $this->countWorkers(),
            'workersByQueue' => $this->workersByQueue(),
            'peakWorkers' => 0, 'uniqueWorkers' => 0, 'jobsPerSecond' => null,
        ];

        if ($batchId === null) {
            return $base;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT
                count(*) as total,
                count(case when picked_up_at is null then 1 end) as pending,
                count(case when picked_up_at is not null and completed_at is null then 1 end) as processing,
                count(case when completed_at is not null and not failed then 1 end) as completed,
                count(case when failed then 1 end) as failed,
                avg(case when completed_at is not null then (picked_up_at - dispatched_at) * 1000 end) as avg_wait_ms,
                avg(case when completed_at is not null then (completed_at - picked_up_at) * 1000 end) as avg_process_ms,
                min(dispatched_at) as min_dispatched_at,
                max(completed_at) as max_completed_at,
                count(distinct worker_id) as unique_workers
             FROM job_metrics WHERE batch_id = ?',
            [$batchId],
        ) ?: [];

        $completed = (int) ($row['completed'] ?? 0);
        $span = (float) ($row['max_completed_at'] ?? 0) - (float) ($row['min_dispatched_at'] ?? 0);

        return array_merge($base, [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'completed' => $completed,
            'failed' => (int) ($row['failed'] ?? 0),
            'avgWaitMs' => isset($row['avg_wait_ms']) ? round((float) $row['avg_wait_ms']) : null,
            'avgProcessMs' => isset($row['avg_process_ms']) ? round((float) $row['avg_process_ms']) : null,
            'totalDurationMs' => $completed > 0 && $span > 0 ? round($span * 1000) : null,
            'peakWorkers' => $this->peakConcurrency($batchId),
            'uniqueWorkers' => (int) ($row['unique_workers'] ?? 0),
            'jobsPerSecond' => $completed > 0 && $span > 0 ? round($completed / $span, 1) : null,
        ]);
    }

    /**
     * @return array<int, array{id:int, number:int, queue:string, worker:?string, waitMs:?float, startMs:?float, endMs:?float, status:string}>
     */
    public function batchJobs(string $batchId): array
    {
        $dispatchedAt = (float) $this->connection->fetchOne(
            'SELECT min(dispatched_at) FROM job_metrics WHERE batch_id = ?',
            [$batchId],
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, job_number, queue, worker_id, dispatched_at, picked_up_at, completed_at, failed
             FROM job_metrics WHERE batch_id = ?
             ORDER BY (picked_up_at is null), picked_up_at asc, job_number asc',
            [$batchId],
        );

        return array_map(static function (array $m) use ($dispatchedAt): array {
            $pickedUp = $m['picked_up_at'] !== null ? (float) $m['picked_up_at'] : null;
            $completed = $m['completed_at'] !== null ? (float) $m['completed_at'] : null;
            $failed = (bool) $m['failed'];

            return [
                'id' => (int) $m['id'],
                'number' => (int) $m['job_number'],
                'queue' => (string) $m['queue'],
                'worker' => $m['worker_id'],
                'waitMs' => $pickedUp !== null ? round(($pickedUp - $dispatchedAt) * 1000) : null,
                'startMs' => $pickedUp !== null ? round(($pickedUp - $dispatchedAt) * 1000) : null,
                'endMs' => $completed !== null ? round(($completed - $dispatchedAt) * 1000) : null,
                'status' => $failed ? 'failed' : ($completed !== null ? 'completed' : ($pickedUp !== null ? 'processing' : 'pending')),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array{id:string, jobCount:int, uniqueWorkers:int, dispatchedAt:string}>
     */
    public function recentBatches(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT batch_id, count(*) as job_count, count(distinct worker_id) as unique_workers, min(dispatched_at) as dispatched_at
             FROM job_metrics GROUP BY batch_id ORDER BY dispatched_at DESC LIMIT 10',
        );

        return array_map(static fn (array $b): array => [
            'id' => (string) $b['batch_id'],
            'jobCount' => (int) $b['job_count'],
            'uniqueWorkers' => (int) $b['unique_workers'],
            'dispatchedAt' => date('H:i:s', (int) $b['dispatched_at']),
        ], $rows);
    }

    /**
     * Peak concurrent workers for a batch, via a sweep line over pickup/complete
     * events.
     */
    public function peakConcurrency(string $batchId): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT picked_up_at, completed_at FROM job_metrics WHERE batch_id = ? AND picked_up_at is not null',
            [$batchId],
        );

        $events = [];
        foreach ($rows as $row) {
            $events[] = ['time' => (float) $row['picked_up_at'], 'type' => 1];
            $events[] = ['time' => $row['completed_at'] !== null ? (float) $row['completed_at'] : PHP_FLOAT_MAX, 'type' => -1];
        }

        usort($events, static fn (array $a, array $b): int => $a['time'] <=> $b['time'] ?: $a['type'] <=> $b['type']);

        $peak = 0;
        $current = 0;
        foreach ($events as $event) {
            $current += $event['type'];
            $peak = max($peak, $current);
        }

        return $peak;
    }
}
