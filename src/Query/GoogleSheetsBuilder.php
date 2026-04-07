<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Query;

use AmazingNL\GoogleSheetsDBAL\GoogleSheetsDatabase;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class GoogleSheetsBuilder extends Builder
{
    public function __construct($connection, $grammar, $processor, private readonly GoogleSheetsDatabase $database)
    {
        parent::__construct($connection, $grammar, $processor);
    }

    protected function runSelect(): array
    {
        return $this->database->select($this);
    }

    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();

        return ! $this->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->limit(1)
            ->get(['*'])
            ->isEmpty();
    }

    public function insert(array $values): bool
    {
        $this->applyBeforeQueryCallbacks();

        return $this->database->insert($this, $values);
    }

    public function insertGetId(array $values, $sequence = null): int|string
    {
        $this->applyBeforeQueryCallbacks();

        return $this->database->insertGetId($this, $values, $sequence);
    }

    public function update(array $values): int
    {
        $this->applyBeforeQueryCallbacks();

        return $this->database->update($this, $values);
    }

    public function delete($id = null): int
    {
        if ($id !== null) {
            $this->where($this->from.'.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        return $this->database->delete($this);
    }

    public function truncate(): void
    {
        $this->applyBeforeQueryCallbacks();

        $this->database->truncate($this);
    }

    public function newQuery(): static
    {
        return new static($this->connection, $this->grammar, $this->processor, $this->database);
    }
}
