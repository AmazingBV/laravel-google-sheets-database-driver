<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver;

use AmazingBV\GoogleSheetsDatabaseDriver\Console\SheetsInstallCommand;
use AmazingBV\GoogleSheetsDatabaseDriver\Migrations\GoogleSheetsMigrationRepository;
use AmazingBV\GoogleSheetsDatabaseDriver\Transports\GoogleSheetsApiTransport;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\ServiceProvider;

class GoogleSheetsDatabaseDriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/google-sheets-dbal.php', 'google-sheets-dbal');

        $this->app->singleton(SheetsInstallCommand::class);

        $this->app->extend('migration.repository', function (DatabaseMigrationRepository $repository, $app) {
            $migrations = $app['config']['database.migrations'];
            $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;

            $custom = new GoogleSheetsMigrationRepository($repository->getConnectionResolver(), $table);

            if (method_exists($repository, 'getConnection')) {
                $custom->setSource($repository->getConnection()->getName());
            }

            return $custom;
        });

        $this->registerDefaultConnectionConfig();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/google-sheets-dbal.php' => config_path('google-sheets-dbal.php'),
        ], 'google-sheets-dbal-config');

        $this->app->make(DatabaseManager::class)->extend('google-sheets', function (array $config, string $name) {
            $config['name'] ??= $name;

            return new GoogleSheetsConnection($this->app, $config);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                SheetsInstallCommand::class,
            ]);
        }
    }

    private function registerDefaultConnectionConfig(): void
    {
        $defaults = $this->app['config']->get('google-sheets-dbal.connection');
        $connection = $this->app['config']->get('database.connections.google-sheets', []);

        if (! is_array($defaults) || $defaults === []) {
            $defaults = [
                'driver' => 'google-sheets',
                'database' => env('DB_DATABASE'),
                'prefix' => '',
                'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH'),
                'cache_store' => env('GOOGLE_SHEETS_CACHE_STORE'),
                'cache_ttl' => (int) env('GOOGLE_SHEETS_CACHE_TTL', 60),
                'quota_retry_attempts' => (int) env('GOOGLE_SHEETS_QUOTA_RETRY_ATTEMPTS', 5),
                'quota_retry_base_delay_ms' => (int) env('GOOGLE_SHEETS_QUOTA_RETRY_BASE_DELAY_MS', 1000),
                'quota_retry_max_delay_ms' => (int) env('GOOGLE_SHEETS_QUOTA_RETRY_MAX_DELAY_MS', 10000),
                'read_requests_per_minute' => (int) env('GOOGLE_SHEETS_READ_REQUESTS_PER_MINUTE', 50),
                'write_requests_per_minute' => (int) env('GOOGLE_SHEETS_WRITE_REQUESTS_PER_MINUTE', 45),
                'schema_sheet' => '__sheetsdbal_schema',
                'migrations_table' => env('GOOGLE_SHEETS_MIGRATIONS_TABLE', 'migrations'),
                'migrations_sheet' => '__sheetsdbal_migrations',
                'transport' => GoogleSheetsApiTransport::class,
            ];
        }

        $this->app['config']->set(
            'database.connections.google-sheets',
            array_replace($defaults, $connection)
        );
    }
}
