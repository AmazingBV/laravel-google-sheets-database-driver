<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL;

use AmazingNL\GoogleSheetsDBAL\Exceptions\UnsupportedSheetsOperation;
use AmazingNL\GoogleSheetsDBAL\Query\GoogleSheetsBuilder;
use AmazingNL\GoogleSheetsDBAL\Schema\GoogleSheetsSchemaBuilder;
use AmazingNL\GoogleSheetsDBAL\Schema\GoogleSheetsSchemaGrammar;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;

class GoogleSheetsConnection extends Connection
{
    private GoogleSheetsDatabase $googleSheetsDatabase;

    public function __construct(Container $container, array $config)
    {
        parent::__construct(null, (string) ($config['database'] ?? ''), (string) ($config['prefix'] ?? ''), $config);

        $this->googleSheetsDatabase = new GoogleSheetsDatabase($container, $config);

        $this->useDefaultQueryGrammar();
        $this->useDefaultSchemaGrammar();
        $this->useDefaultPostProcessor();
    }

    public function getGoogleSheetsDatabase(): GoogleSheetsDatabase
    {
        return $this->googleSheetsDatabase;
    }

    public function query(): GoogleSheetsBuilder
    {
        return new GoogleSheetsBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor(), $this->googleSheetsDatabase);
    }

    public function getSchemaBuilder(): GoogleSheetsSchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new GoogleSheetsSchemaBuilder($this, $this->googleSheetsDatabase);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        throw new UnsupportedSheetsOperation('Raw select statements are not supported by the google-sheets driver.');
    }

    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        throw new UnsupportedSheetsOperation('Cursors are not supported by the google-sheets driver.');
    }

    public function statement($query, $bindings = []): bool
    {
        throw new UnsupportedSheetsOperation('Raw statements are not supported by the google-sheets driver.');
    }

    public function affectingStatement($query, $bindings = []): int
    {
        throw new UnsupportedSheetsOperation('Raw affecting statements are not supported by the google-sheets driver.');
    }

    public function unprepared($query): bool
    {
        throw new UnsupportedSheetsOperation('Unprepared statements are not supported by the google-sheets driver.');
    }

    public function beginTransaction(): void
    {
        throw new UnsupportedSheetsOperation('Transactions are not supported by the google-sheets driver.');
    }

    public function commit(): void
    {
        throw new UnsupportedSheetsOperation('Transactions are not supported by the google-sheets driver.');
    }

    public function rollBack($toLevel = null): void
    {
        throw new UnsupportedSheetsOperation('Transactions are not supported by the google-sheets driver.');
    }

    public function transaction(\Closure $callback, $attempts = 1)
    {
        throw new UnsupportedSheetsOperation('Transactions are not supported by the google-sheets driver.');
    }

    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar(): GoogleSheetsSchemaGrammar
    {
        return new GoogleSheetsSchemaGrammar($this);
    }

    protected function getDefaultPostProcessor(): Processor
    {
        return new Processor;
    }
}
