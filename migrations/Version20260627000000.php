<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the queue dashboard tables: job_metrics and workers.
 *
 * Built with the Schema API rather than raw SQL so the same migration runs on
 * SQLite (local) and Postgres (Laravel Cloud).
 */
final class Version20260627000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the queue dashboard tables (job_metrics, workers).';
    }

    public function up(Schema $schema): void
    {
        $metrics = $schema->createTable('job_metrics');
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

        $workers = $schema->createTable('workers');
        $workers->addColumn('worker_id', 'string', ['length' => 255]);
        $workers->addColumn('queue', 'string', ['length' => 120, 'notnull' => false]);
        $workers->addColumn('started_at', 'datetime_immutable');
        $workers->setPrimaryKey(['worker_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('workers');
        $schema->dropTable('job_metrics');
    }
}
