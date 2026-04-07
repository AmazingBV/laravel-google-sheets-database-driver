<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Transports;

use AmazingNL\GoogleSheetsDBAL\Contracts\SheetsTransport;
use AmazingNL\GoogleSheetsDBAL\Exceptions\ConfigurationException;
use AmazingNL\GoogleSheetsDBAL\Exceptions\GoogleSheetsException;
use Google\Client;
use Google\Exception as GoogleException;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\SpreadsheetProperties;
use Google\Service\Sheets\ValueRange;

class GoogleSheetsApiTransport implements SheetsTransport
{
    private Sheets $service;

    private string $spreadsheetId;

    /**
     * @param  array{database?: string, credentials_path?: string}  $config
     */
    public function __construct(array $config)
    {
        $credentialsPath = $config['credentials_path'] ?? null;
        $this->spreadsheetId = (string) ($config['database'] ?? '');

        if ($credentialsPath === null || $credentialsPath === '') {
            throw new ConfigurationException('GOOGLE_SHEETS_CREDENTIALS_PATH is required for the google-sheets driver.');
        }

        if (! is_file($credentialsPath)) {
            throw new ConfigurationException(sprintf('Google credentials file [%s] does not exist.', $credentialsPath));
        }

        if ($this->spreadsheetId === '') {
            throw new ConfigurationException('DB_DATABASE must contain the target Google Spreadsheet ID.');
        }

        $client = new Client;
        $client->setApplicationName('Laravel Google Sheets DBAL');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Sheets::SPREADSHEETS]);

        $this->service = new Sheets($client);
    }

    public function assertAccessible(): void
    {
        try {
            $this->service->spreadsheets->get($this->spreadsheetId, [
                'fields' => 'spreadsheetId',
            ]);
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException('Unable to access the configured spreadsheet.', previous: $exception);
        }
    }

    public function listSheets(): array
    {
        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId, [
                'fields' => 'sheets(properties(sheetId,title,hidden))',
            ]);
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException('Unable to list sheets for the configured spreadsheet.', previous: $exception);
        }

        $sheets = [];

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();
            $sheets[$properties->getTitle()] = [
                'hidden' => (bool) $properties->getHidden(),
                'sheetId' => $properties->getSheetId(),
            ];
        }

        return $sheets;
    }

    public function getSheetValues(string $title): array
    {
        try {
            $response = $this->service->spreadsheets_values->get(
                $this->spreadsheetId,
                $this->quotedSheetName($title),
                [
                    'valueRenderOption' => 'UNFORMATTED_VALUE',
                    'dateTimeRenderOption' => 'FORMATTED_STRING',
                ]
            );
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException(sprintf('Unable to read sheet [%s].', $title), previous: $exception);
        }

        return array_map(
            static fn (array $row): array => array_values($row),
            $response->getValues() ?? []
        );
    }

    public function setSheetValues(string $title, array $rows): void
    {
        try {
            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                $this->quotedSheetName($title),
                new ClearValuesRequest
            );

            if ($rows === []) {
                return;
            }

            $range = $this->rangeForSize($title, count($rows), max(array_map('count', $rows)) ?: 1);
            $valueRange = new ValueRange(['values' => $rows]);

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $valueRange,
                ['valueInputOption' => 'RAW']
            );
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException(sprintf('Unable to write sheet [%s].', $title), previous: $exception);
        }
    }

    public function createSheet(string $title, bool $hidden = false): void
    {
        try {
            $request = new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new Request([
                        'addSheet' => [
                            'properties' => [
                                'title' => $title,
                                'hidden' => $hidden,
                            ],
                        ],
                    ]),
                ],
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException(sprintf('Unable to create sheet [%s].', $title), previous: $exception);
        }
    }

    public function deleteSheet(string $title): void
    {
        $sheet = $this->listSheets()[$title] ?? null;

        if ($sheet === null) {
            return;
        }

        try {
            $request = new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new Request([
                        'deleteSheet' => [
                            'sheetId' => $sheet['sheetId'],
                        ],
                    ]),
                ],
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException(sprintf('Unable to delete sheet [%s].', $title), previous: $exception);
        }
    }

    public function renameSheet(string $from, string $to): void
    {
        $sheet = $this->listSheets()[$from] ?? null;

        if ($sheet === null) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] does not exist.', $from));
        }

        $this->updateSheetProperties($sheet['sheetId'], ['title' => $to], 'title');
    }

    public function setSheetHidden(string $title, bool $hidden): void
    {
        $sheet = $this->listSheets()[$title] ?? null;

        if ($sheet === null) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] does not exist.', $title));
        }

        $this->updateSheetProperties($sheet['sheetId'], ['hidden' => $hidden], 'hidden');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function updateSheetProperties(int $sheetId, array $properties, string $fields): void
    {
        try {
            $request = new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new Request([
                        'updateSheetProperties' => [
                            'properties' => array_merge(['sheetId' => $sheetId], $properties),
                            'fields' => $fields,
                        ],
                    ]),
                ],
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
        } catch (GoogleException|GoogleServiceException $exception) {
            throw new GoogleSheetsException('Unable to update sheet properties.', previous: $exception);
        }
    }

    private function quotedSheetName(string $title): string
    {
        $escaped = str_replace("'", "''", $title);

        return "'{$escaped}'";
    }

    private function rangeForSize(string $title, int $rows, int $columns): string
    {
        return sprintf(
            "%s!A1:%s%d",
            $this->quotedSheetName($title),
            $this->columnLetter($columns),
            $rows
        );
    }

    private function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }
}
