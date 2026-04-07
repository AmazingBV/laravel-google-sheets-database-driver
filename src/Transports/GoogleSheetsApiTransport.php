<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Transports;

use AmazingBV\GoogleSheetsDatabaseDriver\Contracts\SheetsTransport;
use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\ConfigurationException;
use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\GoogleSheetsException;
use Google\Client;
use Google\Exception as GoogleException;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\ValueRange;

class GoogleSheetsApiTransport implements SheetsTransport
{
    private Sheets $service;

    private string $spreadsheetId;

    /**
     * @var array<string, array{hidden: bool, sheetId: int|null}>|null
     */
    private ?array $sheetDirectoryCache = null;

    /**
     * @var array<string, array<int, array<int, mixed>>>
     */
    private array $sheetValuesCache = [];

    /**
     * @var array{read: list<float>, write: list<float>}
     */
    private array $requestWindow = [
        'read' => [],
        'write' => [],
    ];

    private int $quotaRetryAttempts;

    private int $quotaRetryBaseDelayMs;

    private int $quotaRetryMaxDelayMs;

    private int $readRequestsPerMinute;

    private int $writeRequestsPerMinute;

    /**
     * @param  array{database?: string, credentials_path?: string, quota_retry_attempts?: int, quota_retry_base_delay_ms?: int, quota_retry_max_delay_ms?: int, read_requests_per_minute?: int, write_requests_per_minute?: int}  $config
     */
    public function __construct(array $config)
    {
        $credentialsPath = $config['credentials_path'] ?? null;
        $this->spreadsheetId = (string) ($config['database'] ?? '');
        $this->quotaRetryAttempts = max(0, (int) ($config['quota_retry_attempts'] ?? 5));
        $this->quotaRetryBaseDelayMs = max(1, (int) ($config['quota_retry_base_delay_ms'] ?? 1000));
        $this->quotaRetryMaxDelayMs = max($this->quotaRetryBaseDelayMs, (int) ($config['quota_retry_max_delay_ms'] ?? 10000));
        $this->readRequestsPerMinute = max(0, (int) ($config['read_requests_per_minute'] ?? 50));
        $this->writeRequestsPerMinute = max(0, (int) ($config['write_requests_per_minute'] ?? 45));

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
        $this->fetchSheetDirectory('Unable to access the configured spreadsheet.');
    }

    public function listSheets(): array
    {
        return $this->fetchSheetDirectory('Unable to list sheets for the configured spreadsheet.');
    }

    public function getSheetValues(string $title): array
    {
        if (array_key_exists($title, $this->sheetValuesCache)) {
            return $this->sheetValuesCache[$title];
        }

        $response = $this->runGoogleOperation(
            sprintf('Unable to read sheet [%s].', $title),
            function () use ($title) {
                return $this->service->spreadsheets_values->get(
                    $this->spreadsheetId,
                    $this->quotedSheetName($title),
                    [
                        'valueRenderOption' => 'UNFORMATTED_VALUE',
                        'dateTimeRenderOption' => 'FORMATTED_STRING',
                    ]
                );
            },
            'read'
        );

        return $this->sheetValuesCache[$title] = array_map(
            static fn (array $row): array => array_values($row),
            $response->getValues() ?? []
        );
    }

    public function setSheetValues(string $title, array $rows): void
    {
        $existingRows = $this->sheetValuesCache[$title] ?? null;

        if ($existingRows !== []) {
            $this->runGoogleOperation(
                sprintf('Unable to write sheet [%s].', $title),
                function () use ($title): void {
                    $this->service->spreadsheets_values->clear(
                        $this->spreadsheetId,
                        $this->quotedSheetName($title),
                        new ClearValuesRequest
                    );
                },
                'write'
            );
        }

        if ($rows !== []) {
            $this->runGoogleOperation(
                sprintf('Unable to write sheet [%s].', $title),
                function () use ($title, $rows): void {
                    $range = $this->rangeForSize($title, count($rows), max(array_map('count', $rows)) ?: 1);
                    $valueRange = new ValueRange(['values' => $rows]);

                    $this->service->spreadsheets_values->update(
                        $this->spreadsheetId,
                        $range,
                        $valueRange,
                        ['valueInputOption' => 'RAW']
                    );
                },
                'write'
            );
        }

        $this->sheetValuesCache[$title] = $rows;
    }

    public function createSheet(string $title, bool $hidden = false): void
    {
        $this->runGoogleOperation(
            sprintf('Unable to create sheet [%s].', $title),
            function () use ($title, $hidden): void {
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
            },
            'write'
        );

        $this->invalidateSheetDirectoryCache();
        $this->sheetValuesCache[$title] = [];
    }

    public function deleteSheet(string $title): void
    {
        $sheet = $this->listSheets()[$title] ?? null;

        if ($sheet === null) {
            return;
        }

        $this->runGoogleOperation(
            sprintf('Unable to delete sheet [%s].', $title),
            function () use ($sheet): void {
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
            },
            'write'
        );

        $this->invalidateSheetDirectoryCache();
        unset($this->sheetValuesCache[$title]);
    }

    public function renameSheet(string $from, string $to): void
    {
        $sheet = $this->listSheets()[$from] ?? null;

        if ($sheet === null) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] does not exist.', $from));
        }

        $this->updateSheetProperties($sheet['sheetId'], ['title' => $to], 'title');

        if (array_key_exists($from, $this->sheetValuesCache)) {
            $this->sheetValuesCache[$to] = $this->sheetValuesCache[$from];
            unset($this->sheetValuesCache[$from]);
        }
    }

    public function setSheetHidden(string $title, bool $hidden): void
    {
        $sheet = $this->listSheets()[$title] ?? null;

        if ($sheet === null) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] does not exist.', $title));
        }

        $this->updateSheetProperties($sheet['sheetId'], ['hidden' => $hidden], 'hidden');
    }

    public function renderDatabaseIndexSheet(string $title, array $entries): void
    {
        $sheets = $this->listSheets();
        $sheet = $sheets[$title] ?? null;

        if ($sheet === null) {
            $this->runGoogleOperation(
                sprintf('Unable to create sheet [%s].', $title),
                function () use ($title): void {
                    $request = new BatchUpdateSpreadsheetRequest([
                        'requests' => [
                            new Request([
                                'addSheet' => [
                                    'properties' => [
                                        'title' => $title,
                                        'index' => 0,
                                    ],
                                ],
                            ]),
                        ],
                    ]);

                    $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
                },
                'write'
            );

            $this->invalidateSheetDirectoryCache();
            $sheet = $this->listSheets()[$title] ?? null;
        }

        if ($sheet === null || $sheet['sheetId'] === null) {
            throw new GoogleSheetsException(sprintf('Unable to resolve the [%s] sheet after creation.', $title));
        }

        $sheetId = $sheet['sheetId'];
        $rows = [['Database Index']];

        foreach ($entries as $entry) {
            $label = $entry['title'];

            if ($entry['sheetId'] !== null) {
                $escapedLabel = str_replace('"', '""', $label);
                $label = sprintf('=HYPERLINK("#gid=%d";"%s")', $entry['sheetId'], $escapedLabel);
            }

            $rows[] = [$label];
        }

        $this->runGoogleOperation(
            sprintf('Unable to write sheet [%s].', $title),
            function () use ($title, $rows): void {
                $this->service->spreadsheets_values->clear(
                    $this->spreadsheetId,
                    $this->quotedSheetName($title),
                    new ClearValuesRequest
                );
            },
            'write'
        );

        $this->runGoogleOperation(
            sprintf('Unable to write sheet [%s].', $title),
            function () use ($title, $rows): void {
                $range = $this->rangeForSize($title, count($rows), 1);
                $valueRange = new ValueRange(['values' => $rows]);

                $this->service->spreadsheets_values->update(
                    $this->spreadsheetId,
                    $range,
                    $valueRange,
                    ['valueInputOption' => 'USER_ENTERED']
                );
            },
            'write'
        );

        $this->runGoogleOperation(
            sprintf('Unable to format sheet [%s].', $title),
            function () use ($sheetId): void {
                $request = new BatchUpdateSpreadsheetRequest([
                    'requests' => [
                        new Request([
                            'updateSheetProperties' => [
                                'properties' => [
                                    'sheetId' => $sheetId,
                                    'index' => 0,
                                ],
                                'fields' => 'index',
                            ],
                        ]),
                        new Request([
                            'repeatCell' => [
                                'range' => [
                                    'sheetId' => $sheetId,
                                    'startRowIndex' => 0,
                                    'endRowIndex' => 1,
                                    'startColumnIndex' => 0,
                                    'endColumnIndex' => 1,
                                ],
                                'cell' => [
                                    'userEnteredFormat' => [
                                        'textFormat' => [
                                            'bold' => true,
                                            'fontSize' => 18,
                                        ],
                                    ],
                                ],
                                'fields' => 'userEnteredFormat.textFormat.bold,userEnteredFormat.textFormat.fontSize',
                            ],
                        ]),
                    ],
                ]);

                $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
            },
            'write'
        );

        $this->sheetValuesCache[$title] = array_map(
            static fn (array $row): array => array_values($row),
            $rows
        );
        $this->invalidateSheetDirectoryCache();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function updateSheetProperties(int $sheetId, array $properties, string $fields): void
    {
        $this->runGoogleOperation(
            'Unable to update sheet properties.',
            function () use ($sheetId, $properties, $fields): void {
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
            },
            'write'
        );

        $this->invalidateSheetDirectoryCache();
    }

    /**
     * @return array<string, array{hidden: bool, sheetId: int|null}>
     */
    private function fetchSheetDirectory(string $message): array
    {
        if ($this->sheetDirectoryCache !== null) {
            return $this->sheetDirectoryCache;
        }

        $spreadsheet = $this->runGoogleOperation(
            $message,
            fn () => $this->service->spreadsheets->get($this->spreadsheetId, [
                'fields' => 'sheets(properties(sheetId,title,hidden))',
            ]),
            'read'
        );

        $sheets = [];

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();
            $sheets[$properties->getTitle()] = [
                'hidden' => (bool) $properties->getHidden(),
                'sheetId' => $properties->getSheetId(),
            ];
        }

        return $this->sheetDirectoryCache = $sheets;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function runGoogleOperation(string $message, callable $operation, string $quotaType): mixed
    {
        $attempt = 0;

        retry:
        $this->throttleQuota($quotaType);
        $this->recordQuotaRequest($quotaType);

        try {
            return $operation();
        } catch (GoogleException|GoogleServiceException $exception) {
            if (! $this->shouldRetry($exception) || $attempt >= $this->quotaRetryAttempts) {
                throw $this->googleSheetsException($message, $exception);
            }

            $this->sleepForRetryAttempt($attempt);
            $attempt++;

            goto retry;
        }
    }

    private function shouldRetry(GoogleException|GoogleServiceException $exception): bool
    {
        if ($exception instanceof GoogleServiceException) {
            if (in_array($exception->getCode(), [429, 500, 502, 503, 504], true)) {
                return true;
            }

            foreach ($exception->getErrors() ?? [] as $error) {
                $reason = strtolower((string) ($error['reason'] ?? ''));

                if (in_array($reason, [
                    'ratelimitexceeded',
                    'userratelimitexceeded',
                    'resourcelimitexceeded',
                    'backenderror',
                ], true)) {
                    return true;
                }
            }
        }

        $message = strtolower(trim($exception->getMessage()));

        return str_contains($message, 'quota exceeded')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'resource_exhausted');
    }

    private function throttleQuota(string $quotaType): void
    {
        $limit = $quotaType === 'write'
            ? $this->writeRequestsPerMinute
            : $this->readRequestsPerMinute;

        if ($limit <= 0) {
            return;
        }

        $windowStart = microtime(true) - 60;
        $this->requestWindow[$quotaType] = array_values(array_filter(
            $this->requestWindow[$quotaType],
            static fn (float $timestamp): bool => $timestamp > $windowStart
        ));

        while (count($this->requestWindow[$quotaType]) >= $limit) {
            $oldest = $this->requestWindow[$quotaType][0] ?? microtime(true);
            $sleepSeconds = max(0.05, 60 - (microtime(true) - $oldest) + 0.05);
            usleep((int) ceil($sleepSeconds * 1_000_000));

            $windowStart = microtime(true) - 60;
            $this->requestWindow[$quotaType] = array_values(array_filter(
                $this->requestWindow[$quotaType],
                static fn (float $timestamp): bool => $timestamp > $windowStart
            ));
        }
    }

    private function recordQuotaRequest(string $quotaType): void
    {
        $this->requestWindow[$quotaType][] = microtime(true);
    }

    private function sleepForRetryAttempt(int $attempt): void
    {
        $delayMs = min(
            $this->quotaRetryMaxDelayMs,
            (int) ($this->quotaRetryBaseDelayMs * (2 ** $attempt))
        );

        $jitterMs = random_int(0, max(50, (int) floor($delayMs * 0.25)));

        usleep((int) (($delayMs + $jitterMs) * 1000));
    }

    private function invalidateSheetDirectoryCache(): void
    {
        $this->sheetDirectoryCache = null;
    }

    private function googleSheetsException(string $message, GoogleException|GoogleServiceException $exception): GoogleSheetsException
    {
        $details = trim($exception->getMessage());
        $suffix = $details !== '' ? ' Google API: '.$details : '';

        return new GoogleSheetsException(sprintf(
            '%s Spreadsheet ID [%s].%s',
            $message,
            $this->spreadsheetId,
            $suffix
        ), previous: $exception);
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
