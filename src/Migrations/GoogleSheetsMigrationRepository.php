<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Migrations;

use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\GoogleSheetsException;
use AmazingBV\GoogleSheetsDatabaseDriver\GoogleSheetsConnection;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;

class GoogleSheetsMigrationRepository extends DatabaseMigrationRepository
{
    /**
     * @var list<string>|null
     */
    private ?array $tablesBeforeMigration = null;

    public function getRan()
    {
        $this->pruneStaleCreateTableMigrations();

        $ran = parent::getRan();
        $this->snapshotTablesBeforeNextMigration();

        return $ran;
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

    public function createRepository()
    {
        $connection = $this->getConnection();

        if ($connection->getDriverName() !== 'google-sheets') {
            parent::createRepository();

            return;
        }

        $connection->getSchemaBuilder()->create($this->table, function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
            $table->json('tables')->nullable();
        });
    }

    public function log($file, $batch)
    {
        $connection = $this->getConnection();

        if ($connection->getDriverName() !== 'google-sheets') {
            parent::log($file, $batch);

            return;
        }

        $record = ['migration' => $file, 'batch' => $batch];

        if ($this->ensureMigrationTablesColumn()) {
            $record['tables'] = $this->createdTablesSinceLastSnapshot();
        }

        $this->table()->insert($record);
        $this->snapshotTablesBeforeNextMigration();
    }

    private function pruneStaleCreateTableMigrations(): void
    {
        $connection = $this->getConnection();

        if ($connection->getDriverName() !== 'google-sheets' || ! parent::repositoryExists()) {
            return;
        }

        $schema = $connection->getSchemaBuilder();
        $rows = $this->table()->get();

        foreach ($rows as $row) {
            $tables = $this->tablesForMigrationRow($row);

            if ($tables === []) {
                continue;
            }

            $existing = [];
            $missing = [];

            foreach ($tables as $table) {
                if ($schema->hasTable($table)) {
                    $existing[] = $table;
                } else {
                    $missing[] = $table;
                }
            }

            if ($missing === []) {
                continue;
            }

            if ($existing === []) {
                $this->table()->where('migration', $row->migration)->delete();

                continue;
            }

            throw new GoogleSheetsException(sprintf(
                'Cannot auto-repair migration [%s] because only some created tables are missing. Existing: [%s]. Missing: [%s]. Restore the missing tabs or roll back the existing tabs manually.',
                $row->migration,
                implode(', ', $existing),
                implode(', ', $missing)
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function tablesForMigrationRow(object $row): array
    {
        $tables = $row->tables ?? null;

        if (is_array($tables)) {
            return array_values(array_filter($tables, static fn (mixed $table): bool => is_string($table) && $table !== ''));
        }

        if (is_string($tables) && $tables !== '') {
            $decoded = json_decode($tables, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded, static fn (mixed $table): bool => is_string($table) && $table !== ''));
            }
        }

        $table = $this->inferCreatedTableName((string) $row->migration);

        return $table === null ? [] : [$table];
    }

    private function inferCreatedTableName(string $migration): ?string
    {
        if (preg_match('/create_(.+?)_table(?:$|_)/', $migration, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function ensureMigrationTablesColumn(): bool
    {
        $schema = $this->getConnection()->getSchemaBuilder();

        if ($schema->hasColumn($this->table, 'tables')) {
            return true;
        }

        $schema->table($this->table, function ($table): void {
            $table->json('tables')->nullable();
        });

        return true;
    }

    private function snapshotTablesBeforeNextMigration(): void
    {
        $this->tablesBeforeMigration = $this->currentTableNames();
    }

    /**
     * @return list<string>
     */
    private function createdTablesSinceLastSnapshot(): array
    {
        $before = $this->tablesBeforeMigration ?? [];
        $after = $this->currentTableNames();

        return array_values(array_diff($after, $before));
    }

    /**
     * @return list<string>
     */
    private function currentTableNames(): array
    {
        $connection = $this->getConnection();

        if (! $connection instanceof GoogleSheetsConnection) {
            return [];
        }

        return $connection->getGoogleSheetsDatabase()->getTableNames();
    }
}
