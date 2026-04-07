<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Console;

use AmazingBV\GoogleSheetsDatabaseDriver\GoogleSheetsConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SheetsInstallCommand extends Command
{
    protected $signature = 'sheets:install {--connection=google-sheets}';

    protected $description = 'Validate Google Sheets access and initialize the package system sheets.';

    public function handle(): int
    {
        $connectionName = (string) $this->option('connection');
        $connection = DB::connection($connectionName);

        if (! $connection instanceof GoogleSheetsConnection) {
            $this->error(sprintf('Connection [%s] is not a google-sheets connection.', $connectionName));

            return self::FAILURE;
        }

        $database = $connection->getGoogleSheetsDatabase();
        $database->assertAccessible();
        $database->ensureSystemSheets();
        $database->syncExistingSheets();

        $this->info(sprintf(
            'Google Sheets connection [%s] is ready for spreadsheet [%s].',
            $connectionName,
            $connection->getDatabaseName()
        ));

        return self::SUCCESS;
    }
}
