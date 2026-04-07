<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Data;

final class ColumnSchema
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
        public readonly bool $autoIncrement = false,
        public readonly bool $primary = false,
    ) {
    }

    /**
     * @param  array{name: string, type: string, nullable?: bool, default?: mixed, auto_increment?: bool, primary?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['type'],
            (bool) ($data['nullable'] ?? false),
            $data['default'] ?? null,
            (bool) ($data['auto_increment'] ?? false),
            (bool) ($data['primary'] ?? false),
        );
    }

    /**
     * @return array{name: string, type: string, nullable: bool, default: mixed, auto_increment: bool, primary: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'auto_increment' => $this->autoIncrement,
            'primary' => $this->primary,
        ];
    }
}
