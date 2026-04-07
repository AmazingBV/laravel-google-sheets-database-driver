<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver;

use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\UnsupportedSheetsOperation;
use AmazingBV\GoogleSheetsDatabaseDriver\Query\GoogleSheetsBuilder;
use AmazingBV\GoogleSheetsDatabaseDriver\Schema\GoogleSheetsSchemaBuilder;
use AmazingBV\GoogleSheetsDatabaseDriver\Schema\GoogleSheetsSchemaGrammar;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Throwable;

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
        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        $this->transactions++;

        $this->transactionsManager?->begin(
            $this->getName(),
            $this->transactions
        );

        $this->fireConnectionEvent('beganTransaction');
    }

    public function commit(): void
    {
        [$levelBeingCommitted, $this->transactions] = [
            $this->transactions,
            max(0, $this->transactions - 1),
        ];

        $this->transactionsManager?->commit(
            $this->getName(),
            $levelBeingCommitted,
            $this->transactions
        );

        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null): void
    {
        $toLevel = is_null($toLevel)
            ? $this->transactions - 1
            : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->transactions) {
            return;
        }

        $this->transactions = $toLevel;

        $this->transactionsManager?->rollback(
            $this->getName(),
            $this->transactions
        );

        $this->fireConnectionEvent('rollingBack');
    }

    public function transaction(\Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
            } catch (Throwable $exception) {
                $this->rollBack();

                throw $exception;
            }

            $this->commit();

            return $result;
        }

        return null;
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
