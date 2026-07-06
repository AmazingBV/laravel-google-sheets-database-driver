<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver;

use AmazingBV\GoogleSheetsDatabaseDriver\Contracts\SheetsTransport;
use AmazingBV\GoogleSheetsDatabaseDriver\Data\ColumnSchema;
use AmazingBV\GoogleSheetsDatabaseDriver\Data\TableSchema;
use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\ConfigurationException;
use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\GoogleSheetsException;
use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\SchemaMismatchException;
use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\UnsupportedSheetsOperation;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use JsonException;

class GoogleSheetsDatabase
{
    private const DATABASE_INDEX_SHEET = 'Database Index';

    private readonly SheetsTransport $transport;

    private readonly string $spreadsheetId;

    private readonly string $schemaSheet;

    private readonly string $migrationsTable;

    private readonly string $migrationsSheet;

    private readonly ?CacheRepository $cache;

    private readonly int $cacheTtl;

    private readonly int $lockTtlSeconds;

    private readonly int $lockWaitSeconds;

    /**
     * @var array<string, array{hidden: bool, sheetId: int|null}>|null
     */
    private ?array $sheetDirectoryCache = null;

    /**
     * @var array<string, array<int, array<int, mixed>>>
     */
    private array $sheetValuesCache = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly Container $container, private readonly array $config)
    {
        $this->spreadsheetId = (string) ($config['database'] ?? '');
        $this->schemaSheet = (string) ($config['schema_sheet'] ?? '__sheetsdbal_schema');
        $this->migrationsTable = (string) ($config['migrations_table'] ?? 'migrations');
        $this->migrationsSheet = (string) ($config['migrations_sheet'] ?? '__sheetsdbal_migrations');
        $this->cacheTtl = max(0, (int) ($config['cache_ttl'] ?? 0));
        $this->lockTtlSeconds = max(1, (int) ($config['lock_ttl_seconds'] ?? 30));
        $this->lockWaitSeconds = max(0, (int) ($config['lock_wait_seconds'] ?? 10));

        if ($this->spreadsheetId === '') {
            throw new ConfigurationException('DB_DATABASE must contain the target Google Spreadsheet ID.');
        }

        $transport = $config['transport'] ?? null;

        if (! is_string($transport) || $transport === '') {
            throw new ConfigurationException('A valid transport class must be configured for the google-sheets driver.');
        }

        $instance = $container->make($transport, ['config' => $config]);

        if (! $instance instanceof SheetsTransport) {
            throw new ConfigurationException(sprintf('Transport [%s] must implement %s.', $transport, SheetsTransport::class));
        }

        $this->transport = $instance;
        $this->cache = $this->resolveCache();
    }

    public function assertAccessible(): void
    {
        $this->transport->assertAccessible();
    }

    public function ensureSystemSheets(): void
    {
        $sheets = $this->listSheets();

        if (! isset($sheets[$this->schemaSheet])) {
            $this->createSheet($this->schemaSheet, true);
            $this->setSheetValues($this->schemaSheet, [
                ['table', 'columns', 'next_id', 'hidden'],
            ]);
        } else {
            $this->setSheetHidden($this->schemaSheet, true);
        }

        if (! $this->hasTable($this->migrationsTable)) {
            $this->createTableInternal(
                $this->migrationsTable,
                [
                    new ColumnSchema('id', 'integer', false, null, true, true),
                    new ColumnSchema('migration', 'string'),
                    new ColumnSchema('batch', 'integer'),
                ],
                true
            );
        }

        $this->syncDatabaseIndexSheet();
    }

    public function syncExistingSheets(): void
    {
        $this->ensureMetadataSheetInitialized();

        $metadata = $this->loadMetadata();

        foreach ($this->listSheets() as $sheet => $properties) {
            if ($this->isNonTableSheet($sheet)) {
                continue;
            }

            if (isset($metadata[$sheet])) {
                continue;
            }

            $values = $this->getSheetValues($sheet);

            if ($values === []) {
                continue;
            }

            $header = $this->normalizeHeaderRow($values[0], $sheet);

            $schema = new TableSchema(
                $sheet,
                array_map(static fn (string $column): ColumnSchema => new ColumnSchema(
                    $column,
                    $column === 'id' ? 'integer' : 'string',
                    true,
                    null,
                    $column === 'id',
                    $column === 'id',
                ), $header),
                $this->inferNextId($values, $header),
                (bool) $properties['hidden']
            );

            $metadata[$sheet] = $schema;
        }

        $this->persistMetadata($metadata);
        $this->syncDatabaseIndexSheet();
    }

    public function hasTable(string $table): bool
    {
        $logical = $this->normalizeTableName($table);

        if ($this->isNonTableSheet($logical)) {
            return false;
        }

        $metadata = $this->loadMetadata();
        $physicalExists = $this->physicalTableExists($logical);

        if (isset($metadata[$logical])) {
            if (! $physicalExists) {
                $this->removeStaleMetadataEntry($logical, $metadata);

                return false;
            }

            return true;
        }

        return $this->getTableSchema($logical) !== null;
    }

    public function getColumnListing(string $table): array
    {
        return $this->getTableSchema($table)?->header() ?? [];
    }

    public function applyBlueprint(Blueprint $blueprint): void
    {
        $this->withTableLock((string) $blueprint->getTable(), function () use ($blueprint): void {
            $table = $this->normalizeTableName($blueprint->getTable());

            if ($blueprint->creating()) {
                $this->createTableInternal(
                    $table,
                    array_map(fn (ColumnDefinition $column): ColumnSchema => $this->columnFromDefinition($column), $blueprint->getColumns()),
                    $table === $this->migrationsTable
                );

                return;
            }

            if (method_exists($blueprint, 'getChangedColumns') && $blueprint->getChangedColumns() !== []) {
                throw new UnsupportedSheetsOperation('Changing existing column definitions is not supported by the google-sheets driver.');
            }

            foreach ($blueprint->getCommands() as $command) {
                if ($command instanceof ColumnDefinition) {
                    $this->addColumn($table, $command);

                    continue;
                }

                match ($command->name) {
                    'rename' => $table = $this->renameTable($table, (string) $command->to),
                    'drop' => $this->dropTable($table),
                    'dropIfExists' => $this->hasTable($table) ? $this->dropTable($table) : null,
                    'renameColumn' => $this->renameColumn($table, (string) $command->from, (string) $command->to),
                    'dropColumn' => $this->dropColumns($table, Arr::wrap($command->columns)),
                    'primary', 'index', 'unique', 'foreign', 'dropPrimary', 'dropIndex', 'dropUnique', 'dropForeign', 'renameIndex',
                    'fulltext', 'fullText', 'spatialIndex', 'vectorIndex', 'dropFullText', 'dropSpatialIndex', 'dropVectorIndex' => null,
                    default => throw new UnsupportedSheetsOperation(sprintf('Schema command [%s] is not supported by the google-sheets driver.', $command->name ?? 'unknown')),
                };
            }
        });
    }

    /**
     * @return array<int, object>
     */
    public function select(Builder $query): array
    {
        $this->assertSupportedSelect($query);

        $rows = $this->buildJoinedRows($query);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->rowMatches($query->wheres ?? [], $row, (string) $query->from)));
        $rows = $this->applyOrdering($rows, $query);

        if ($query->aggregate !== null) {
            return [(object) ['aggregate' => $this->calculateAggregate($rows, $query)]];
        }

        $rows = $this->applyOffsetAndLimit($rows, $query);
        $rows = $this->projectRows($rows, $query);

        return array_map(static fn (array $row): object => (object) $row, $rows);
    }

    public function insert(Builder $query, array $values): bool
    {
        return $this->withTableLock((string) $query->from, function () use ($query, $values): bool {
            $rowsToInsert = $this->normalizeInsertPayload($values);
            $snapshot = $this->loadTableSnapshot((string) $query->from);
            $schema = $snapshot['schema'];
            $rows = $snapshot['rows'];

            foreach ($rowsToInsert as $row) {
                $prepared = $this->prepareInsertRow($schema, $row);
                $rows[] = $prepared['row'];
                $schema = $prepared['schema'];
            }

            $this->writeTable($schema, $rows);

            return true;
        });
    }

    public function insertGetId(Builder $query, array $values, mixed $sequence = null): int|string
    {
        return $this->withTableLock((string) $query->from, function () use ($query, $values, $sequence): int|string {
            $snapshot = $this->loadTableSnapshot((string) $query->from);
            $prepared = $this->prepareInsertRow($snapshot['schema'], $values);

            $snapshot['rows'][] = $prepared['row'];
            $this->writeTable($prepared['schema'], $snapshot['rows']);

            return $prepared['row'][$sequence ?? 'id'] ?? throw new GoogleSheetsException('Unable to determine the inserted record ID.');
        });
    }

    public function update(Builder $query, array $values): int
    {
        return $this->withTableLock((string) $query->from, function () use ($query, $values): int {
            $this->assertMutatingQuerySupported($query);

            $snapshot = $this->loadTableSnapshot((string) $query->from);
            $schema = $snapshot['schema'];
            $rows = $snapshot['rows'];
            $indexes = $this->matchingRowIndexes($rows, $query);
            $normalized = $this->normalizeWriteValues($values, $schema->table);

            foreach ($indexes as $index) {
                foreach ($normalized as $column => $value) {
                    $schema->requireColumn($column);
                    $rows[$index][$column] = $this->normalizeValueForStorage($value, $schema->requireColumn($column));
                }

                if ($schema->hasColumn('updated_at') && ! array_key_exists('updated_at', $normalized)) {
                    $rows[$index]['updated_at'] = Carbon::now()->format('Y-m-d H:i:s');
                }
            }

            $this->writeTable($schema, $rows);

            return count($indexes);
        });
    }

    public function delete(Builder $query): int
    {
        return $this->withTableLock((string) $query->from, function () use ($query): int {
            $this->assertMutatingQuerySupported($query);

            $snapshot = $this->loadTableSnapshot((string) $query->from);
            $rows = $snapshot['rows'];
            $indexes = array_flip($this->matchingRowIndexes($rows, $query));

            $remaining = array_values(array_filter(
                $rows,
                static fn (array $row, int $index): bool => ! isset($indexes[$index]),
                ARRAY_FILTER_USE_BOTH
            ));

            $this->writeTable($snapshot['schema'], $remaining);

            return count($indexes);
        });
    }

    public function truncate(Builder $query): void
    {
        $this->withTableLock((string) $query->from, function () use ($query): void {
            $snapshot = $this->loadTableSnapshot((string) $query->from);

            $this->writeTable($snapshot['schema']->withNextId(1), []);
        });
    }

    /**
     * @return array{schema: TableSchema, rows: list<array<string, mixed>>}
     */
    private function loadTableSnapshot(string $table): array
    {
        $logical = $this->normalizeTableName($table);
        $cacheKey = $this->cacheKey("table:{$logical}");

        if ($this->cache !== null && ($snapshot = $this->cache->get($cacheKey)) !== null) {
            return $snapshot;
        }

        $schema = $this->getTableSchema($logical);

        if ($schema === null) {
            throw new GoogleSheetsException(sprintf('Table [%s] does not exist.', $logical));
        }

        $physical = $this->mapPhysicalTable($logical);
        $values = $this->getSheetValues($physical);

        if ($values === []) {
            $snapshot = ['schema' => $schema, 'rows' => []];
            $this->remember($cacheKey, $snapshot);

            return $snapshot;
        }

        $header = $this->normalizeHeaderRow($values[0], $logical);

        if ($header !== $schema->header()) {
            throw new SchemaMismatchException(sprintf(
                'Sheet header mismatch for table [%s]. Expected [%s], received [%s].',
                $logical,
                implode(', ', $schema->header()),
                implode(', ', $header)
            ));
        }

        $rows = [];

        foreach (array_slice($values, 1) as $row) {
            $mapped = $this->mapRow($row, $schema);

            if ($this->rowIsEmpty($mapped)) {
                continue;
            }

            $rows[] = $mapped;
        }

        $snapshot = ['schema' => $schema, 'rows' => $rows];
        $this->remember($cacheKey, $snapshot);

        return $snapshot;
    }

    private function writeTable(TableSchema $schema, array $rows): void
    {
        $schema = $schema->syncNextId($rows);
        $physical = $this->mapPhysicalTable($schema->table);
        $payload = [$schema->header()];

        foreach ($rows as $row) {
            $payload[] = array_map(
                fn (ColumnSchema $column): mixed => $this->serializeValue($row[$column->name] ?? null, $column),
                $schema->columns
            );
        }

        $this->setSheetValues($physical, $payload);

        $metadata = $this->loadMetadata();
        $metadata[$schema->table] = $schema;
        $this->persistMetadata($metadata);
        $this->forgetTableCache($schema->table);
    }

    private function createTableInternal(string $table, array $columns, bool $hidden): void
    {
        $logical = $this->normalizeTableName($table);

        if ($this->isNonTableSheet($logical)) {
            throw new GoogleSheetsException(sprintf('Table name [%s] is reserved by the google-sheets driver.', $logical));
        }

        if ($this->hasTable($logical)) {
            throw new GoogleSheetsException(sprintf('Table [%s] already exists.', $logical));
        }

        $this->ensureMetadataSheetInitialized();
        $this->validateColumns($logical, $columns);

        $schema = new TableSchema($logical, $columns, 1, $hidden);
        $physical = $this->mapPhysicalTable($logical);
        $sheets = $this->listSheets();

        if (! isset($sheets[$physical])) {
            $this->createSheet($physical, $hidden);
        }

        $this->setSheetHidden($physical, $hidden);
        $this->setSheetValues($physical, [$schema->header()]);

        $metadata = $this->loadMetadata();
        $metadata[$logical] = $schema;
        $this->persistMetadata($metadata);
        $this->forgetTableCache($logical);
        $this->syncDatabaseIndexSheet();
    }

    private function addColumn(string $table, ColumnDefinition $definition): void
    {
        $snapshot = $this->loadTableSnapshot($table);
        $schema = $snapshot['schema'];

        if ($schema->hasColumn($definition->name)) {
            throw new GoogleSheetsException(sprintf('Column [%s] already exists on table [%s].', $definition->name, $table));
        }

        $column = $this->columnFromDefinition($definition);
        $columns = [...$schema->columns, $column];
        $schema = $schema->withColumns($columns);

        foreach ($snapshot['rows'] as &$row) {
            $row[$column->name] = $column->default;
        }

        $this->writeTable($schema, $snapshot['rows']);
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        $snapshot = $this->loadTableSnapshot($table);
        $columns = [];
        $found = false;

        foreach ($snapshot['schema']->columns as $column) {
            if ($column->name === $to) {
                throw new GoogleSheetsException(sprintf('Column [%s] already exists on table [%s].', $to, $table));
            }

            if ($column->name === $from) {
                $columns[] = new ColumnSchema($to, $column->type, $column->nullable, $column->default, $column->autoIncrement, $column->primary);
                $found = true;
                continue;
            }

            $columns[] = $column;
        }

        if (! $found) {
            throw new GoogleSheetsException(sprintf('Column [%s] does not exist on table [%s].', $from, $table));
        }

        $rows = array_map(function (array $row) use ($from, $to): array {
            $row[$to] = $row[$from] ?? null;
            unset($row[$from]);

            return $row;
        }, $snapshot['rows']);

        $this->writeTable($snapshot['schema']->withColumns($columns), $rows);
    }

    /**
     * @param  list<string>  $columnsToDrop
     */
    private function dropColumns(string $table, array $columnsToDrop): void
    {
        $snapshot = $this->loadTableSnapshot($table);

        if (in_array('id', $columnsToDrop, true)) {
            throw new UnsupportedSheetsOperation('The required [id] column cannot be dropped from a google-sheets table.');
        }

        $columns = array_values(array_filter(
            $snapshot['schema']->columns,
            static fn (ColumnSchema $column): bool => ! in_array($column->name, $columnsToDrop, true)
        ));

        $rows = array_map(function (array $row) use ($columnsToDrop): array {
            foreach ($columnsToDrop as $column) {
                unset($row[$column]);
            }

            return $row;
        }, $snapshot['rows']);

        $this->writeTable($snapshot['schema']->withColumns($columns), $rows);
    }

    private function renameTable(string $from, string $to): string
    {
        $from = $this->normalizeTableName($from);
        $to = $this->normalizeTableName($to);

        if (! $this->hasTable($from)) {
            throw new GoogleSheetsException(sprintf('Table [%s] does not exist.', $from));
        }

        if ($this->hasTable($to)) {
            throw new GoogleSheetsException(sprintf('Table [%s] already exists.', $to));
        }

        $schema = $this->getTableSchema($from);

        if ($schema === null) {
            throw new GoogleSheetsException(sprintf('Table [%s] does not exist.', $from));
        }

        $this->renameSheet($this->mapPhysicalTable($from), $this->mapPhysicalTable($to));
        $this->setSheetHidden($this->mapPhysicalTable($to), $to === $this->migrationsTable);

        $metadata = $this->loadMetadata();
        unset($metadata[$from]);
        $metadata[$to] = $schema->withTable($to);
        $this->persistMetadata($metadata);
        $this->forgetTableCache($from);
        $this->forgetTableCache($to);
        $this->syncDatabaseIndexSheet();

        return $to;
    }

    private function dropTable(string $table): void
    {
        $logical = $this->normalizeTableName($table);
        $this->deleteSheet($this->mapPhysicalTable($logical));

        $metadata = $this->loadMetadata();
        unset($metadata[$logical]);
        $this->persistMetadata($metadata);
        $this->forgetTableCache($logical);
        $this->syncDatabaseIndexSheet();
    }

    private function ensureMetadataSheetInitialized(): void
    {
        if (! isset($this->listSheets()[$this->schemaSheet])) {
            $this->createSheet($this->schemaSheet, true);
        }

        $this->setSheetHidden($this->schemaSheet, true);

        if ($this->getSheetValues($this->schemaSheet) === []) {
            $this->setSheetValues($this->schemaSheet, [
                ['table', 'columns', 'next_id', 'hidden'],
            ]);
        }
    }

    /**
     * @return array<string, TableSchema>
     */
    private function loadMetadata(): array
    {
        $cacheKey = $this->cacheKey('metadata');

        if ($this->cache !== null && ($metadata = $this->cache->get($cacheKey)) !== null) {
            return $metadata;
        }

        if (! isset($this->listSheets()[$this->schemaSheet])) {
            return [];
        }

        $values = $this->getSheetValues($this->schemaSheet);

        if ($values === []) {
            return [];
        }

        $header = array_map(static fn (mixed $value): string => (string) $value, $values[0]);
        $metadata = [];

        foreach (array_slice($values, 1) as $row) {
            $mapped = [];

            foreach ($header as $index => $column) {
                $mapped[$column] = $row[$index] ?? null;
            }

            if (($mapped['table'] ?? null) === null || $mapped['table'] === '') {
                continue;
            }

            $schema = TableSchema::fromMetadataRow($mapped);
            $metadata[$schema->table] = $schema;
        }

        $this->remember($cacheKey, $metadata);

        return $metadata;
    }

    /**
     * @param  array<string, TableSchema>  $metadata
     */
    private function persistMetadata(array $metadata): void
    {
        $rows = [['table', 'columns', 'next_id', 'hidden']];

        foreach ($metadata as $schema) {
            $rows[] = array_values($schema->toMetadataRow());
        }

        $this->setSheetValues($this->schemaSheet, $rows);
        $this->forgetMetadataCache();
    }

    private function getTableSchema(string $table): ?TableSchema
    {
        $logical = $this->normalizeTableName($table);

        if ($this->isNonTableSheet($logical)) {
            return null;
        }

        $metadata = $this->loadMetadata();
        $physicalExists = $this->physicalTableExists($logical);

        if (isset($metadata[$logical])) {
            if (! $physicalExists) {
                $this->removeStaleMetadataEntry($logical, $metadata);

                return null;
            }

            return $metadata[$logical];
        }

        if (! $physicalExists) {
            return null;
        }

        $physical = $this->mapPhysicalTable($logical);
        $sheets = $this->listSheets();
        $values = $this->getSheetValues($physical);

        if ($values === []) {
            return null;
        }

        $header = $this->normalizeHeaderRow($values[0], $logical);

        return new TableSchema(
            $logical,
            array_map(static fn (string $column): ColumnSchema => new ColumnSchema(
                $column,
                $column === 'id' ? 'integer' : 'string',
                true,
                null,
                $column === 'id',
                $column === 'id'
            ), $header),
            $this->inferNextId($values, $header),
            (bool) $sheets[$physical]['hidden']
        );
    }

    /**
     * @param  array<int, array<int, mixed>>  $values
     * @param  list<string>  $header
     */
    private function inferNextId(array $values, array $header): int
    {
        $idIndex = array_search('id', $header, true);

        if ($idIndex === false) {
            return 1;
        }

        $max = 0;

        foreach (array_slice($values, 1) as $row) {
            $value = $row[$idIndex] ?? null;

            if (is_numeric($value)) {
                $max = max($max, (int) $value);
            }
        }

        return $max + 1;
    }

    /**
     * @param  list<ColumnSchema>  $columns
     */
    private function validateColumns(string $table, array $columns): void
    {
        if ($columns === []) {
            throw new GoogleSheetsException(sprintf('Table [%s] must define at least one column.', $table));
        }

        $names = array_map(static fn (ColumnSchema $column): string => $column->name, $columns);

        if (count($names) !== count(array_unique($names))) {
            throw new GoogleSheetsException(sprintf('Table [%s] contains duplicate column names.', $table));
        }

    }

    private function columnFromDefinition(ColumnDefinition $definition): ColumnSchema
    {
        $type = $this->normalizeColumnType((string) $definition->type, (bool) ($definition->autoIncrement ?? false), (string) $definition->name);

        return new ColumnSchema(
            (string) $definition->name,
            $type,
            (bool) ($definition->nullable ?? false),
            $definition->default ?? null,
            (bool) ($definition->autoIncrement ?? false) || $type === 'id',
            (bool) ($definition->primary ?? false) || ($definition->name === 'id')
        );
    }

    private function normalizeColumnType(string $type, bool $autoIncrement, string $name): string
    {
        if ($name === 'id' && $autoIncrement) {
            return 'id';
        }

        return match ($type) {
            'string', 'char' => 'string',
            'text', 'mediumText', 'longText' => 'text',
            'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger', 'unsignedBigInteger' => 'integer',
            'float', 'double', 'decimal' => 'float',
            'boolean' => 'boolean',
            'date' => 'date',
            'dateTime', 'dateTimeTz', 'datetime' => 'dateTime',
            'timestamp', 'timestampTz' => 'timestamp',
            'json', 'jsonb' => 'json',
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            default => throw new UnsupportedSheetsOperation(sprintf('Column type [%s] is not supported by the google-sheets driver.', $type)),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function projectRows(array $rows, Builder $query): array
    {
        $columns = $query->columns ?? ['*'];
        $hasJoins = ! empty($query->joins);

        if ($columns === ['*']) {
            if (! $hasJoins) {
                return array_map(static fn (array $row): array => array_filter(
                    $row,
                    static fn (mixed $value, string $key): bool => ! str_contains($key, '.'),
                    ARRAY_FILTER_USE_BOTH
                ), $rows);
            }

            return $rows;
        }

        return array_map(function (array $row) use ($columns, $query): array {
            $projection = [];

            foreach ($columns as $column) {
                if ($column instanceof Expression) {
                    throw new UnsupportedSheetsOperation('Raw select expressions are not supported by the google-sheets driver.');
                }

                $column = (string) $column;

                if ($column === '*') {
                    $projection = array_merge($projection, $row);
                    continue;
                }

                if (preg_match('/^(.+)\s+as\s+(.+)$/i', $column, $matches) === 1) {
                    $projection[$matches[2]] = $this->resolveValue($row, trim($matches[1]), (string) $query->from);
                    continue;
                }

                if (str_ends_with($column, '.*')) {
                    $table = substr($column, 0, -2);

                    foreach ($row as $key => $value) {
                        if (str_starts_with($key, $table.'.')) {
                            $projection[substr($key, strlen($table) + 1)] = $value;
                        }
                    }

                    continue;
                }

                $projection[$this->projectionKey($column)] = $this->resolveValue($row, $column, (string) $query->from);
            }

            return $projection;
        }, $rows);
    }

    private function projectionKey(string $column): string
    {
        return str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function calculateAggregate(array $rows, Builder $query): int|float|null
    {
        $function = strtolower((string) ($query->aggregate['function'] ?? ''));
        $columns = Arr::wrap($query->aggregate['columns'] ?? ['*']);
        $column = (string) ($columns[0] ?? '*');

        return match ($function) {
            'count' => count($rows),
            'sum' => array_sum(array_map(fn (array $row): float|int => (float) ($this->resolveValue($row, $column, (string) $query->from) ?? 0), $rows)),
            'avg' => $rows === [] ? 0 : array_sum(array_map(fn (array $row): float|int => (float) ($this->resolveValue($row, $column, (string) $query->from) ?? 0), $rows)) / count($rows),
            'min' => $this->extremeValue($rows, $column, (string) $query->from, 'min'),
            'max' => $this->extremeValue($rows, $column, (string) $query->from, 'max'),
            default => throw new UnsupportedSheetsOperation(sprintf('Aggregate [%s] is not supported by the google-sheets driver.', $function)),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function extremeValue(array $rows, string $column, string $defaultTable, string $type): int|float|string|null
    {
        $values = array_values(array_filter(
            array_map(fn (array $row): mixed => $this->resolveValue($row, $column, $defaultTable), $rows),
            static fn (mixed $value): bool => $value !== null
        ));

        if ($values === []) {
            return null;
        }

        return $type === 'min' ? min($values) : max($values);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function buildJoinedRows(Builder $query): array
    {
        $rows = $this->contextualRows((string) $query->from, true);

        foreach ($query->joins ?? [] as $join) {
            if (! $join instanceof JoinClause || $join->type !== 'inner') {
                throw new UnsupportedSheetsOperation('Only simple inner joins are supported by the google-sheets driver.');
            }

            $joinedRows = $this->contextualRows((string) $join->table, false);
            $combined = [];

            foreach ($rows as $leftRow) {
                foreach ($joinedRows as $rightRow) {
                    $candidate = array_merge($leftRow, $rightRow);

                    if ($this->rowMatches($join->wheres ?? [], $candidate, (string) $join->table)) {
                        $combined[] = $candidate;
                    }
                }
            }

            $rows = $combined;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contextualRows(string $table, bool $includeUnqualified): array
    {
        $snapshot = $this->loadTableSnapshot($table);
        $rows = [];

        foreach ($snapshot['rows'] as $row) {
            $contextual = [];

            foreach ($row as $column => $value) {
                $contextual[$table.'.'.$column] = $value;

                if ($includeUnqualified) {
                    $contextual[$column] = $value;
                }
            }

            $rows[] = $contextual;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyOrdering(array $rows, Builder $query): array
    {
        if (empty($query->orders)) {
            return $rows;
        }

        usort($rows, function (array $left, array $right) use ($query): int {
            foreach ($query->orders as $order) {
                if (($order['type'] ?? null) === 'Raw') {
                    throw new UnsupportedSheetsOperation('Raw order clauses are not supported by the google-sheets driver.');
                }

                $column = (string) ($order['column'] ?? '');
                $direction = strtolower((string) ($order['direction'] ?? 'asc')) === 'desc' ? -1 : 1;
                $leftValue = $this->resolveValue($left, $column, (string) $query->from);
                $rightValue = $this->resolveValue($right, $column, (string) $query->from);

                if ($leftValue === $rightValue) {
                    continue;
                }

                return ($leftValue <=> $rightValue) * $direction;
            }

            return 0;
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyOffsetAndLimit(array $rows, Builder $query): array
    {
        $offset = $query->offset ?? 0;
        $limit = $query->limit;

        if ($offset > 0 || $limit !== null) {
            $rows = array_slice($rows, (int) $offset, $limit === null ? null : (int) $limit);
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function matchingRowIndexes(array $rows, Builder $query): array
    {
        $contextualRows = array_map(function (array $row) use ($query): array {
            $contextual = [];

            foreach ($row as $column => $value) {
                $contextual[$column] = $value;
                $contextual[$query->from.'.'.$column] = $value;
            }

            return $contextual;
        }, $rows);

        $pairs = [];

        foreach ($contextualRows as $index => $row) {
            if ($this->rowMatches($query->wheres ?? [], $row, (string) $query->from)) {
                $pairs[] = ['index' => $index, 'row' => $row];
            }
        }

        if (! empty($query->orders)) {
            usort($pairs, function (array $left, array $right) use ($query): int {
                foreach ($query->orders as $order) {
                    $column = (string) ($order['column'] ?? '');
                    $direction = strtolower((string) ($order['direction'] ?? 'asc')) === 'desc' ? -1 : 1;
                    $comparison = $this->resolveValue($left['row'], $column, (string) $query->from) <=> $this->resolveValue($right['row'], $column, (string) $query->from);

                    if ($comparison !== 0) {
                        return $comparison * $direction;
                    }
                }

                return 0;
            });
        }

        $pairs = array_values(array_slice($pairs, (int) ($query->offset ?? 0), $query->limit ?? null));

        return array_map(static fn (array $pair): int => $pair['index'], $pairs);
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private function rowMatches(array $wheres, array $row, string $defaultTable): bool
    {
        if ($wheres === []) {
            return true;
        }

        $groups = [];
        $currentGroup = [];

        foreach ($wheres as $where) {
            $boolean = (string) ($where['boolean'] ?? 'and');
            $negated = str_contains($boolean, ' not');
            $boolean = str_starts_with($boolean, 'or') ? 'or' : 'and';

            $match = $this->evaluateWhere($where, $row, $defaultTable);

            if ($negated) {
                $match = ! $match;
            }

            if ($boolean === 'or' && $currentGroup !== []) {
                $groups[] = $currentGroup;
                $currentGroup = [];
            }

            $currentGroup[] = $match;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        foreach ($groups as $group) {
            if (! in_array(false, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function evaluateWhere(array $where, array $row, string $defaultTable): bool
    {
        return match ($where['type'] ?? null) {
            'Basic' => $this->compareValues(
                $this->resolveValue($row, (string) $where['column'], $defaultTable),
                (string) $where['operator'],
                $where['value'] ?? null
            ),
            'Null' => $this->resolveValue($row, (string) $where['column'], $defaultTable) === null,
            'NotNull' => $this->resolveValue($row, (string) $where['column'], $defaultTable) !== null,
            'In' => in_array($this->resolveValue($row, (string) $where['column'], $defaultTable), Arr::wrap($where['values'] ?? []), true),
            'NotIn' => ! in_array($this->resolveValue($row, (string) $where['column'], $defaultTable), Arr::wrap($where['values'] ?? []), true),
            'Like' => $this->matchesLike(
                $this->resolveValue($row, (string) $where['column'], $defaultTable),
                (string) ($where['value'] ?? ''),
                (bool) ($where['not'] ?? false),
                (bool) ($where['caseSensitive'] ?? false)
            ),
            'Column' => $this->compareValues(
                $this->resolveValue($row, (string) $where['first'], $defaultTable),
                (string) $where['operator'],
                $this->resolveValue($row, (string) $where['second'], $defaultTable)
            ),
            'Nested' => $this->rowMatches($where['query']->wheres ?? [], $row, $defaultTable),
            default => throw new UnsupportedSheetsOperation(sprintf('Where clause type [%s] is not supported by the google-sheets driver.', $where['type'] ?? 'unknown')),
        };
    }

    private function compareValues(mixed $left, string $operator, mixed $right): bool
    {
        return match (strtolower($operator)) {
            '=', '==' => $left == $right,
            '!=', '<>' => $left != $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            default => throw new UnsupportedSheetsOperation(sprintf('Operator [%s] is not supported by the google-sheets driver.', $operator)),
        };
    }

    private function matchesLike(mixed $value, string $pattern, bool $not, bool $caseSensitive): bool
    {
        $value = (string) ($value ?? '');
        $quoted = preg_quote($pattern, '/');
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], $quoted).'$/'.($caseSensitive ? '' : 'i');
        $match = preg_match($regex, $value) === 1;

        return $not ? ! $match : $match;
    }

    private function resolveValue(array $row, string $column, string $defaultTable): mixed
    {
        $column = trim($column);

        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        if (! str_contains($column, '.')) {
            $qualified = $defaultTable.'.'.$column;

            if (array_key_exists($qualified, $row)) {
                return $row[$qualified];
            }

            $matches = array_filter(
                array_keys($row),
                static fn (string $key): bool => str_ends_with($key, '.'.$column)
            );

            if (count($matches) === 1) {
                return $row[array_values($matches)[0]];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>|array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function normalizeInsertPayload(array $values): array
    {
        if ($values === []) {
            throw new GoogleSheetsException('Insert payload cannot be empty.');
        }

        $first = Arr::first($values);

        if (! is_array($first)) {
            return [$values];
        }

        /** @var list<array<string, mixed>> $values */
        return array_values($values);
    }

    /**
     * @return array{schema: TableSchema, row: array<string, mixed>}
     */
    private function prepareInsertRow(TableSchema $schema, array $values): array
    {
        $row = [];
        $now = Carbon::now()->format('Y-m-d H:i:s');

        foreach ($schema->columns as $column) {
            $value = $values[$column->name] ?? $column->default;

            if ($column->autoIncrement && $column->name === 'id' && ($value === null || $value === '')) {
                $value = $schema->nextId;
                $schema = $schema->withNextId($schema->nextId + 1);
            }

            if (($column->name === 'created_at' || $column->name === 'updated_at') && ($value === null || $value === '')) {
                $value = $now;
            }

            if (($column->type === 'uuid' || $column->type === 'ulid') && ($value === null || $value === '') && $column->name === 'id') {
                $value = $column->type === 'uuid' ? (string) Str::uuid() : (string) Str::ulid();
            }

            if ($value === null && ! $column->nullable && $column->default === null && ! $column->autoIncrement) {
                throw new GoogleSheetsException(sprintf('Column [%s] on table [%s] does not allow null values.', $column->name, $schema->table));
            }

            $row[$column->name] = $this->normalizeValueForStorage($value, $column);
        }

        return ['schema' => $schema, 'row' => $row];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeWriteValues(array $values, string $table): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if ($value instanceof Expression) {
                throw new UnsupportedSheetsOperation('Raw expressions are not supported by the google-sheets driver.');
            }
        }

        foreach ($values as $column => $value) {
            if (str_contains($column, '.')) {
                [$prefix, $column] = explode('.', $column, 2);

                if ($prefix !== $table) {
                    throw new UnsupportedSheetsOperation(sprintf(
                        'Qualified update column [%s] does not match the base table [%s].',
                        $prefix,
                        $table
                    ));
                }
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    private function normalizeValueForStorage(mixed $value, ColumnSchema $column): mixed
    {
        if ($value === '') {
            $value = null;
        }

        if ($value === null) {
            return null;
        }

        return match ($column->type) {
            'id', 'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'json' => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            'date' => Carbon::parse((string) $value)->format('Y-m-d'),
            'dateTime', 'timestamp' => Carbon::parse((string) $value)->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }

    private function serializeValue(mixed $value, ColumnSchema $column): mixed
    {
        if ($value === null) {
            return '';
        }

        return match ($column->type) {
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row, TableSchema $schema): array
    {
        $mapped = [];

        foreach ($schema->columns as $index => $column) {
            $mapped[$column->name] = $this->normalizeValueForStorage($row[$index] ?? null, $column);
        }

        return $mapped;
    }

    /**
     * @param  array<int, mixed>  $header
     * @return list<string>
     */
    private function normalizeHeaderRow(array $header, string $table): array
    {
        $normalized = array_map(static fn (mixed $value): string => trim((string) $value), $header);

        if (in_array('', $normalized, true)) {
            throw new SchemaMismatchException(sprintf('Table [%s] contains an empty header cell.', $table));
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new SchemaMismatchException(sprintf('Table [%s] contains duplicate header names.', $table));
        }

        return array_values($normalized);
    }

    private function normalizeTableName(string $table): string
    {
        if ($table === '') {
            throw new GoogleSheetsException('A table name is required for the google-sheets driver.');
        }

        if (preg_match('/\s+as\s+/i', $table) === 1) {
            throw new UnsupportedSheetsOperation('Table aliases are not supported by the google-sheets driver.');
        }

        return $table;
    }

    private function mapPhysicalTable(string $logical): string
    {
        return $logical === $this->migrationsTable ? $this->migrationsSheet : $logical;
    }

    private function assertSupportedSelect(Builder $query): void
    {
        if ($query->unions !== null && $query->unions !== []) {
            throw new UnsupportedSheetsOperation('Unions are not supported by the google-sheets driver.');
        }

        if ($query->havings !== null && $query->havings !== []) {
            throw new UnsupportedSheetsOperation('Having clauses are not supported by the google-sheets driver.');
        }

        if ($query->groups !== null && $query->groups !== []) {
            throw new UnsupportedSheetsOperation('Group by clauses are not supported by the google-sheets driver.');
        }

        foreach ($query->joins ?? [] as $join) {
            if (! $join instanceof JoinClause || $join->type !== 'inner') {
                throw new UnsupportedSheetsOperation('Only simple inner joins are supported by the google-sheets driver.');
            }
        }
    }

    private function assertMutatingQuerySupported(Builder $query): void
    {
        if (! empty($query->joins)) {
            throw new UnsupportedSheetsOperation('Updates and deletes on joined queries are not supported by the google-sheets driver.');
        }
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function resolveCache(): ?CacheRepository
    {
        $store = $this->config['cache_store'] ?? null;

        if ($this->cacheTtl === 0 || $store === null || $store === '' || ! $this->container->bound(CacheFactory::class)) {
            return null;
        }

        $factory = $this->container->make(CacheFactory::class);

        return $factory->store((string) $store);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTableLock(string $table, callable $callback): mixed
    {
        if ($this->cache === null) {
            return $callback();
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store
            ->lock($this->cacheKey('lock:'.$this->normalizeTableName($table)), $this->lockTtlSeconds)
            ->block($this->lockWaitSeconds, $callback);
    }

    private function cacheKey(string $suffix): string
    {
        return sprintf('google-sheets-dbal:%s:%s', $this->spreadsheetId, $suffix);
    }

    private function remember(string $key, mixed $value): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->put($key, $value, $this->cacheTtl);
    }

    private function forgetMetadataCache(): void
    {
        $this->cache?->forget($this->cacheKey('metadata'));
    }

    private function forgetTableCache(string $table): void
    {
        $this->cache?->forget($this->cacheKey('table:'.$this->normalizeTableName($table)));
    }

    private function syncDatabaseIndexSheet(): void
    {
        $entries = [];

        foreach ($this->listSheets() as $title => $properties) {
            if ($this->isNonTableSheet($title) || (bool) ($properties['hidden'] ?? false)) {
                continue;
            }

            $entries[] = [
                'title' => $title,
                'sheetId' => $properties['sheetId'],
            ];
        }

        $this->transport->renderDatabaseIndexSheet(self::DATABASE_INDEX_SHEET, $entries);
        $this->sheetDirectoryCache = null;
    }

    private function isNonTableSheet(string $title): bool
    {
        return in_array($title, [
            $this->schemaSheet,
            $this->migrationsSheet,
            self::DATABASE_INDEX_SHEET,
        ], true);
    }

    private function physicalTableExists(string $table): bool
    {
        return isset($this->listSheets()[$this->mapPhysicalTable($table)]);
    }

    /**
     * @param  array<string, TableSchema>  $metadata
     */
    private function removeStaleMetadataEntry(string $table, array $metadata): void
    {
        if (! isset($metadata[$table])) {
            return;
        }

        unset($metadata[$table]);
        $this->persistMetadata($metadata);
        $this->forgetTableCache($table);
    }

    /**
     * @return array<string, array{hidden: bool, sheetId: int|null}>
     */
    private function listSheets(): array
    {
        return $this->sheetDirectoryCache ??= $this->transport->listSheets();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function getSheetValues(string $title): array
    {
        return $this->sheetValuesCache[$title] ??= $this->transport->getSheetValues($title);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function setSheetValues(string $title, array $rows): void
    {
        $this->transport->setSheetValues($title, $rows);
        $this->sheetValuesCache[$title] = $rows;
    }

    private function createSheet(string $title, bool $hidden = false): void
    {
        $this->transport->createSheet($title, $hidden);
        $this->sheetDirectoryCache = null;
        $this->sheetValuesCache[$title] = [];
    }

    private function deleteSheet(string $title): void
    {
        $this->transport->deleteSheet($title);
        $this->sheetDirectoryCache = null;
        unset($this->sheetValuesCache[$title]);
    }

    private function renameSheet(string $from, string $to): void
    {
        $this->transport->renameSheet($from, $to);
        $this->sheetDirectoryCache = null;

        if (array_key_exists($from, $this->sheetValuesCache)) {
            $this->sheetValuesCache[$to] = $this->sheetValuesCache[$from];
            unset($this->sheetValuesCache[$from]);
        }
    }

    private function setSheetHidden(string $title, bool $hidden): void
    {
        $this->transport->setSheetHidden($title, $hidden);
        $this->sheetDirectoryCache = null;
    }
}
