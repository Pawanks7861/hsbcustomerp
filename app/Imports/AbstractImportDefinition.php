<?php

namespace App\Imports;

use App\Imports\Contracts\ImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use Carbon\Carbon;
use Illuminate\Support\Str;

abstract class AbstractImportDefinition implements ImportDefinition
{
    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES, DuplicateMode::UPDATE_MATCHED];
    }

    public function defaultMode(): string
    {
        return DuplicateMode::CREATE_ONLY;
    }

    public function exampleRows(): array
    {
        return [];
    }

    public function instructions(): string
    {
        return 'Required columns are marked with *. Dates use YYYY-MM-DD. Do not change header names. Upload .xlsx or .csv.';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    protected function castRow(array $raw): array
    {
        $data = [];
        $errors = [];

        foreach ($this->columns() as $column) {
            $value = $raw[$column->key] ?? null;

            if (($value === null || $value === '') && $column->required) {
                $errors[] = $column->heading.' is required.';
                continue;
            }

            if ($value === null || $value === '') {
                $data[$column->key] = null;
                continue;
            }

            $cast = $this->castValue($value, $column);
            if ($cast['error'] !== null) {
                $errors[] = $cast['error'];
            } else {
                $data[$column->key] = $cast['value'];
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    protected function castValue(mixed $value, ColumnDefinition $column): array
    {
        $string = is_string($value) ? trim($value) : $value;

        return match ($column->type) {
            'string' => $this->castString($string, $column),
            'decimal' => $this->castDecimal($string, $column),
            'integer' => is_numeric($string)
                ? ['value' => (int) $string, 'error' => null]
                : ['value' => null, 'error' => $column->heading.' must be a whole number.'],
            'boolean' => $this->castBoolean($string, $column),
            'date' => $this->castDate($string, $column),
            'enum' => $this->castEnum($string, $column),
            default => ['value' => $string, 'error' => null],
        };
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function castString(mixed $value, ColumnDefinition $column): array
    {
        $value = (string) $value;
        if ($column->max !== null && Str::length($value) > $column->max) {
            return ['value' => null, 'error' => $column->heading.' must be at most '.$column->max.' characters.'];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function castDecimal(mixed $value, ColumnDefinition $column): array
    {
        if (! is_numeric($value)) {
            return ['value' => null, 'error' => $column->heading.' must be a number.'];
        }

        return ['value' => round((float) $value, 3), 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function castBoolean(mixed $value, ColumnDefinition $column): array
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'active'], true)) {
            return ['value' => true, 'error' => null];
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'inactive'], true)) {
            return ['value' => false, 'error' => null];
        }

        return ['value' => null, 'error' => $column->heading.' must be yes/no or true/false.'];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function castDate(mixed $value, ColumnDefinition $column): array
    {
        try {
            if (is_numeric($value)) {
                $date = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value));
            } else {
                $date = Carbon::parse((string) $value);
            }

            return ['value' => $date->toDateString(), 'error' => null];
        } catch (\Throwable) {
            return ['value' => null, 'error' => $column->heading.' must be a valid date (YYYY-MM-DD).'];
        }
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function castEnum(mixed $value, ColumnDefinition $column): array
    {
        $value = strtolower(trim((string) $value));
        $allowed = array_map('strtolower', $column->allowed ?? []);
        if (! in_array($value, $allowed, true)) {
            return ['value' => null, 'error' => $column->heading.' must be one of: '.implode(', ', $column->allowed ?? [])];
        }

        $index = array_search($value, $allowed, true);

        return ['value' => $column->allowed[$index], 'error' => null];
    }

    protected function decideAction(ImportContext $context, ?int $existingId): array
    {
        if ($existingId === null) {
            if ($context->mode === DuplicateMode::UPDATE_MATCHED) {
                return ['status' => RowOutcome::INVALID, 'action' => null, 'error' => 'No existing record matched the update key.'];
            }

            return ['status' => RowOutcome::VALID, 'action' => 'create', 'error' => null];
        }

        return match ($context->mode) {
            DuplicateMode::CREATE_ONLY => [
                'status' => RowOutcome::DUPLICATE,
                'action' => null,
                'error' => 'A record with this key already exists. Use Skip or Update mode.',
            ],
            DuplicateMode::SKIP_DUPLICATES => [
                'status' => RowOutcome::DUPLICATE,
                'action' => 'skip',
                'error' => null,
            ],
            DuplicateMode::UPDATE_MATCHED, DuplicateMode::UPSERT => [
                'status' => RowOutcome::DUPLICATE,
                'action' => 'update',
                'error' => null,
            ],
            default => [
                'status' => RowOutcome::INVALID,
                'action' => null,
                'error' => 'Unsupported duplicate mode.',
            ],
        };
    }
}
