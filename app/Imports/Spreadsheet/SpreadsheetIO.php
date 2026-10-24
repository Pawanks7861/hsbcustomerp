<?php

namespace App\Imports\Spreadsheet;

use App\Imports\Contracts\ImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Models\Project;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class SpreadsheetIO
{
    public const MAX_BYTES = 10_485_760;

    public const MAX_ROWS = 5_000;

    /**
     * @return array{ok: bool, message: ?string, headers: list<string>, rows: list<array{row: int, values: array<string, mixed>}>}
     */
    public function read(string $path, ImportDefinition $definition): array
    {
        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'The file could not be read. Upload a valid .xlsx or .csv file.', 'headers' => [], 'rows' => []];
        }

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $highestRow = min((int) $sheet->getHighestRow(), self::MAX_ROWS + 50);

        if ($highestRow < 1 || $highestColumn < 1) {
            return ['ok' => false, 'message' => 'The file is empty.', 'headers' => [], 'rows' => []];
        }

        $headerCells = [];
        for ($col = 1; $col <= $highestColumn; $col++) {
            $headerCells[$col] = $this->normalizeHeader((string) $sheet->getCell([$col, 1])->getFormattedValue());
        }

        $map = $this->mapHeaders($headerCells, $definition->columns());

        if ($map === null) {
            $expected = implode(', ', array_map(fn (ColumnDefinition $c) => $c->heading, $definition->columns()));

            return [
                'ok' => false,
                'message' => 'Column headers do not match the template. Expected: '.$expected,
                'headers' => array_values(array_filter($headerCells)),
                'rows' => [],
            ];
        }

        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $values = [];
            $empty = true;

            foreach ($map as $key => $col) {
                $raw = $sheet->getCell([$col, $row])->getFormattedValue();
                if ($raw instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $raw = $raw->getPlainText();
                }
                $value = is_string($raw) ? trim(\App\Support\SafeInput::plainText($raw)) : $raw;
                if ($value !== null && $value !== '') {
                    $empty = false;
                }
                $values[$key] = $value === '' ? null : $value;
            }

            if ($empty) {
                continue;
            }

            $rows[] = ['row' => $row, 'values' => $values];

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        if ($rows === []) {
            return ['ok' => false, 'message' => 'No data rows were found under the header row.', 'headers' => array_keys($map), 'rows' => []];
        }

        return ['ok' => true, 'message' => null, 'headers' => array_keys($map), 'rows' => $rows];
    }

    public function template(ImportDefinition $definition, ?User $user = null, ?Project $project = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($definition->templateSheetTitle(), 0, 31));

        $columns = $definition->columns();
        foreach ($columns as $index => $column) {
            $heading = $column->heading.($column->required ? ' *' : '');
            $sheet->setCellValue([$index + 1, 1], $heading);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth(max(14, strlen($heading) + 2));
        }

        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($columns)).'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($columns)).'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F1F5F9');

        $examples = $definition->exampleRowsFor($user, $project);
        foreach ($examples as $offset => $example) {
            foreach ($columns as $index => $column) {
                $sheet->setCellValue([$index + 1, $offset + 2], $example[$column->key] ?? '');
            }
        }

        $notesRow = count($examples) + 4;
        $sheet->setCellValue([1, $notesRow], 'Instructions');
        $sheet->getStyle([1, $notesRow])->getFont()->setBold(true);
        $sheet->setCellValue([1, $notesRow + 1], $definition->instructions());
        $sheet->mergeCells([1, $notesRow + 1, min(6, max(1, count($columns))), $notesRow + 3]);
        $sheet->getStyle([1, $notesRow + 1])->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');

        $sheetNames = [];
        foreach ($definition->extraSheets($user, $project) as $extra) {
            $title = substr((string) $extra['title'], 0, 31);
            $ref = $spreadsheet->createSheet();
            $ref->setTitle($title);
            $headings = $extra['headings'] ?? [];
            foreach ($headings as $index => $heading) {
                $ref->setCellValue([$index + 1, 1], $heading);
                $width = $extra['widths'][$index] ?? max(16, strlen((string) $heading) + 2);
                $ref->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
            }
            if ($headings !== []) {
                $ref->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headings)).'1')->getFont()->setBold(true);
            }
            foreach ($extra['rows'] ?? [] as $offset => $row) {
                foreach ($row as $index => $value) {
                    $ref->setCellValue([$index + 1, $offset + 2], $value);
                }
            }
            $ref->freezePane('A2');
            $sheetNames[$title] = $ref->getHighestDataRow();
        }

        $spreadsheet->setActiveSheetIndex(0);

        foreach ($definition->dropdowns() as $dropdown) {
            $columnIndex = null;
            foreach ($columns as $index => $column) {
                if ($column->key === $dropdown['column_key']) {
                    $columnIndex = $index + 1;
                    break;
                }
            }
            if ($columnIndex === null) {
                continue;
            }

            $sheetTitle = $dropdown['sheet'];
            $rangeColumn = $dropdown['range_column'] ?? 'A';
            $lastRow = max(2, (int) ($sheetNames[$sheetTitle] ?? 2));
            $letter = Coordinate::stringFromColumnIndex($columnIndex);
            $validation = $sheet->getCell($letter.'2')->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setShowDropDown(true);
            $validation->setErrorTitle('Invalid location');
            $validation->setError('Choose a location from the Available Locations sheet. Create missing locations in the ERP first.');
            $validation->setPromptTitle('Existing locations');
            $validation->setPrompt('Locations must already exist in the ERP. CSV files do not support dropdowns.');
            $quoted = str_replace("'", "''", $sheetTitle);
            $validation->setFormula1("'{$quoted}'!\${$rangeColumn}\$2:\${$rangeColumn}\${$lastRow}");
            $sheet->setDataValidation($letter.'2:'.$letter.'1001', $validation);
        }

        return $spreadsheet;
    }

    public function download(ImportDefinition $definition, string $format = 'xlsx', ?User $user = null, ?Project $project = null)
    {
        $spreadsheet = $this->template($definition, $user, $project);
        $filename = $definition->key().'-import-template.'.$format;

        if ($format === 'csv') {
            $writer = new CsvWriter($spreadsheet);
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setSheetIndex(0);
        } else {
            $writer = new Xlsx($spreadsheet);
        }

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function errorReport(array $errors, string $format = 'csv')
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Row', 'Column', 'Value', 'Error'], null, 'A1');

        $r = 2;
        foreach ($errors as $error) {
            $sheet->fromArray([
                $error['row'] ?? '',
                $error['column'] ?? '',
                \App\Support\SafeInput::spreadsheetCell((string) ($error['value'] ?? '')),
                $error['message'] ?? '',
            ], null, 'A'.$r);
            $r++;
        }

        $writer = $format === 'xlsx' ? new Xlsx($spreadsheet) : new CsvWriter($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'import-errors.'.$format, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ]);
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\*$/', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

        return $value;
    }

    /**
     * @param  array<int, string>  $headerCells
     * @param  list<ColumnDefinition>  $columns
     * @return array<string, int>|null
     */
    private function mapHeaders(array $headerCells, array $columns): ?array
    {
        $map = [];

        foreach ($columns as $column) {
            $candidates = array_merge(
                [$this->normalizeHeader($column->heading), $this->normalizeHeader($column->key)],
                array_map(fn (string $alias) => $this->normalizeHeader($alias), $column->aliases)
            );

            $found = null;
            foreach ($headerCells as $col => $normalized) {
                if ($normalized !== '' && in_array($normalized, $candidates, true)) {
                    $found = $col;
                    break;
                }
            }

            if ($found === null && $column->required) {
                return null;
            }

            if ($found !== null) {
                $map[$column->key] = $found;
            }
        }

        return $map === [] ? null : $map;
    }
}
