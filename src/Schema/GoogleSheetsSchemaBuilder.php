<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Schema;

use AmazingBV\GoogleSheetsDatabaseDriver\GoogleSheetsDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

class GoogleSheetsSchemaBuilder extends SchemaBuilder
{
    public function __construct($connection, private readonly GoogleSheetsDatabase $database)
    {
        parent::__construct($connection);
    }

    public function hasTable($table): bool
    {
        return $this->database->hasTable((string) $table);
    }

    public function getColumnListing($table): array
    {
        return $this->database->getColumnListing((string) $table);
    }

    public function hasColumn($table, $column): bool
    {
        return in_array($column, $this->getColumnListing($table), true);
    }

    protected function build(Blueprint $blueprint): void
    {
        $this->database->applyBlueprint($blueprint);
    }
}
