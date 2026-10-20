<?php

namespace App\Imports\Contracts;

use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;

interface ImportDefinition
{
    public function key(): string;

    public function label(): string;

    public function permission(): string;

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(): array;

    /**
     * @return list<string>
     */
    public function supportedModes(): array;

    public function defaultMode(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function exampleRows(): array;

    public function instructions(): string;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome;

    /**
     * @param  array<string, mixed>  $data
     */
    public function commit(RowOutcome $row, ImportContext $context): string;
}
