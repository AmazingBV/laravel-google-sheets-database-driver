<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Contracts;

interface SheetsTransport
{
    public function assertAccessible(): void;

    /**
     * @return array<string, array{hidden: bool, sheetId: int|null}>
     */
    public function listSheets(): array;

    /**
     * @return array<int, array<int, mixed>>
     */
    public function getSheetValues(string $title): array;

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function setSheetValues(string $title, array $rows): void;

    public function createSheet(string $title, bool $hidden = false): void;

    public function deleteSheet(string $title): void;

    public function renameSheet(string $from, string $to): void;

    public function setSheetHidden(string $title, bool $hidden): void;
}
