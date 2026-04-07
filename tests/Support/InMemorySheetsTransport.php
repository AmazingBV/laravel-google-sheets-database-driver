<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Tests\Support;

use AmazingNL\GoogleSheetsDBAL\Contracts\SheetsTransport;
use AmazingNL\GoogleSheetsDBAL\Exceptions\GoogleSheetsException;

class InMemorySheetsTransport implements SheetsTransport
{
    /**
     * @var array<string, array{counter: int, sheets: array<string, array{hidden: bool, sheetId: int, rows: array<int, array<int, mixed>>}>}>
     */
    private static array $spreadsheets = [];

    private string $spreadsheetId;

    /**
     * @param  array{database?: string}  $config
     */
    public function __construct(array $config)
    {
        $this->spreadsheetId = (string) ($config['database'] ?? 'testing');

        self::$spreadsheets[$this->spreadsheetId] ??= [
            'counter' => 1,
            'sheets' => [],
        ];
    }

    /**
     * @param  array<string, array{hidden?: bool, rows?: array<int, array<int, mixed>>}>  $sheets
     */
    public static function seed(string $spreadsheetId, array $sheets): void
    {
        self::$spreadsheets[$spreadsheetId] = [
            'counter' => 1,
            'sheets' => [],
        ];

        foreach ($sheets as $title => $sheet) {
            self::$spreadsheets[$spreadsheetId]['sheets'][$title] = [
                'hidden' => (bool) ($sheet['hidden'] ?? false),
                'sheetId' => self::$spreadsheets[$spreadsheetId]['counter']++,
                'rows' => $sheet['rows'] ?? [],
            ];
        }
    }

    public static function reset(): void
    {
        self::$spreadsheets = [];
    }

    /**
     * @return array<string, array{hidden: bool, sheetId: int, rows: array<int, array<int, mixed>>}>
     */
    public static function snapshot(string $spreadsheetId): array
    {
        return self::$spreadsheets[$spreadsheetId]['sheets'] ?? [];
    }

    public function assertAccessible(): void
    {
    }

    public function listSheets(): array
    {
        $sheets = [];

        foreach (self::$spreadsheets[$this->spreadsheetId]['sheets'] as $title => $sheet) {
            $sheets[$title] = [
                'hidden' => $sheet['hidden'],
                'sheetId' => $sheet['sheetId'],
            ];
        }

        return $sheets;
    }

    public function getSheetValues(string $title): array
    {
        return self::$spreadsheets[$this->spreadsheetId]['sheets'][$title]['rows'] ?? [];
    }

    public function setSheetValues(string $title, array $rows): void
    {
        if (! isset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$title])) {
            $this->createSheet($title);
        }

        self::$spreadsheets[$this->spreadsheetId]['sheets'][$title]['rows'] = $rows;
    }

    public function createSheet(string $title, bool $hidden = false): void
    {
        if (isset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$title])) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] already exists.', $title));
        }

        self::$spreadsheets[$this->spreadsheetId]['sheets'][$title] = [
            'hidden' => $hidden,
            'sheetId' => self::$spreadsheets[$this->spreadsheetId]['counter']++,
            'rows' => [],
        ];
    }

    public function deleteSheet(string $title): void
    {
        unset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$title]);
    }

    public function renameSheet(string $from, string $to): void
    {
        if (! isset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$from])) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] does not exist.', $from));
        }

        if (isset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$to])) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] already exists.', $to));
        }

        self::$spreadsheets[$this->spreadsheetId]['sheets'][$to] = self::$spreadsheets[$this->spreadsheetId]['sheets'][$from];
        unset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$from]);
    }

    public function setSheetHidden(string $title, bool $hidden): void
    {
        if (! isset(self::$spreadsheets[$this->spreadsheetId]['sheets'][$title])) {
            throw new GoogleSheetsException(sprintf('Sheet [%s] does not exist.', $title));
        }

        self::$spreadsheets[$this->spreadsheetId]['sheets'][$title]['hidden'] = $hidden;
    }
}
