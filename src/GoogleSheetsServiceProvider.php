<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL;

use AmazingNL\GoogleSheetsDBAL\Console\SheetsInstallCommand;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\ServiceProvider;

class GoogleSheetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/google-sheets-dbal.php', 'google-sheets-dbal');

        $this->app->singleton(SheetsInstallCommand::class);

        $this->app->extend('migration.repository', function (DatabaseMigrationRepository $repository) {
            return $repository;
        });

        $this->registerDefaultConnectionConfig();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/google-sheets-dbal.php' => config_path('google-sheets-dbal.php'),
        ], 'google-sheets-dbal-config');

        $this->app->make(DatabaseManager::class)->extend('google-sheets', function (array $config) {
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
        $defaults = $this->app['config']->get('google-sheets-dbal.connection', []);
        $connection = $this->app['config']->get('database.connections.google-sheets', []);

        $this->app['config']->set(
            'database.connections.google-sheets',
            array_replace($defaults, $connection)
        );
    }
}
