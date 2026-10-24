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

    /**
     * @return list<array<string, mixed>>
     */
    public function exampleRowsFor(?\App\Models\User $user = null, ?\App\Models\Project $project = null): array;

    public function templateSheetTitle(): string;

    /**
     * Extra workbook sheets used by Excel templates (ignored for CSV).
     *
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>, widths?: list<int>}>
     */
    public function extraSheets(?\App\Models\User $user = null, ?\App\Models\Project $project = null): array;

    /**
     * Dropdowns for Excel templates. `column_key` matches a ColumnDefinition key.
     *
     * @return list<array{column_key: string, sheet: string, range_column: string}>
     */
    public function dropdowns(): array;

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
