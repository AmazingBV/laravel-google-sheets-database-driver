<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Migrations;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;

class GoogleSheetsMigrationRepository extends DatabaseMigrationRepository
{
    public function getRan()
    {
        $this->pruneStaleCreateTableMigrations();

        return parent::getRan();
    }

    public function getMigrations($steps)
    {
        $this->pruneStaleCreateTableMigrations();

        return parent::getMigrations($steps);
    }

    public function getMigrationsByBatch($batch)
    {
        $this->pruneStaleCreateTableMigrations();

        return parent::getMigrationsByBatch($batch);
    }

    public function getLast()
    {
        $this->pruneStaleCreateTableMigrations();

        return parent::getLast();
    }

    public function getMigrationBatches()
    {
        $this->pruneStaleCreateTableMigrations();

        return parent::getMigrationBatches();
    }

    public function getNextBatchNumber()
    {
        $this->pruneStaleCreateTableMigrations();

        return parent::getNextBatchNumber();
    }

    private function pruneStaleCreateTableMigrations(): void
    {
        $connection = $this->getConnection();

        if ($connection->getDriverName() !== 'google-sheets' || ! parent::repositoryExists()) {
            return;
        }

        $schema = $connection->getSchemaBuilder();
        $rows = $this->table()->get(['migration']);

        foreach ($rows as $row) {
            $table = $this->inferCreatedTableName((string) $row->migration);

            if ($table === null) {
                continue;
            }

            if (! $schema->hasTable($table)) {
                $this->table()->where('migration', $row->migration)->delete();
            }
        }
    }

    private function inferCreatedTableName(string $migration): ?string
    {
        if (preg_match('/create_(.+?)_table(?:$|_)/', $migration, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
