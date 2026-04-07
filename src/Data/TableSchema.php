<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Data;

use Illuminate\Support\Collection;

final class TableSchema
{
    /**
     * @param  list<ColumnSchema>  $columns
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly int $nextId = 1,
        public readonly bool $hidden = false,
    ) {
    }

    /**
     * @param  array{table: string, columns: string, next_id?: int|string|null, hidden?: bool|string|null}  $row
     */
    public static function fromMetadataRow(array $row): self
    {
        /** @var list<array{name: string, type: string, nullable?: bool, default?: mixed, auto_increment?: bool, primary?: bool}> $columns */
        $columns = json_decode((string) $row['columns'], true, 512, JSON_THROW_ON_ERROR);

        return new self(
            (string) $row['table'],
            array_map(static fn (array $column): ColumnSchema => ColumnSchema::fromArray($column), $columns),
            (int) ($row['next_id'] ?? 1),
            filter_var($row['hidden'] ?? false, FILTER_VALIDATE_BOOL)
        );
    }

    /**
     * @return array{table: string, columns: string, next_id: int, hidden: bool}
     */
    public function toMetadataRow(): array
    {
        return [
            'table' => $this->table,
            'columns' => json_encode(array_map(
                static fn (ColumnSchema $column): array => $column->toArray(),
                $this->columns
            ), JSON_THROW_ON_ERROR),
            'next_id' => $this->nextId,
            'hidden' => $this->hidden,
        ];
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        return array_map(static fn (ColumnSchema $column): string => $column->name, $this->columns);
    }

    public function hasColumn(string $column): bool
    {
        return $this->column($column) !== null;
    }

    public function column(string $column): ?ColumnSchema
    {
        return Collection::make($this->columns)
            ->first(static fn (ColumnSchema $definition): bool => $definition->name === $column);
    }

    public function requireColumn(string $column): ColumnSchema
    {
        return $this->column($column) ?? throw new \InvalidArgumentException(sprintf(
            'Column [%s] does not exist on table [%s].',
            $column,
            $this->table
        ));
    }

    /**
     * @param  list<ColumnSchema>  $columns
     */
    public function withColumns(array $columns): self
    {
        return new self($this->table, $columns, $this->nextId, $this->hidden);
    }

    public function withTable(string $table): self
    {
        return new self($table, $this->columns, $this->nextId, $this->hidden);
    }

    public function withNextId(int $nextId): self
    {
        return new self($this->table, $this->columns, $nextId, $this->hidden);
    }

    public function syncNextId(array $rows): self
    {
        $idColumn = $this->column('id');

        if (! $idColumn?->autoIncrement) {
            return $this;
        }

        $max = 0;

        foreach ($rows as $row) {
            $value = $row['id'] ?? null;

            if (is_numeric($value)) {
                $max = max($max, (int) $value);
            }
        }

        return $this->withNextId(max($this->nextId, $max + 1));
    }
}
