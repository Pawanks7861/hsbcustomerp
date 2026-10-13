<?php

namespace App\Services;

use App\Support\Money;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class BoqSpreadsheet
{
    public const HEADERS = [
        'Sr. No.',
        'Service Code',
        'Item Description',
        'Unit',
        'B.O.Q.',
        'Rate',
        'BOQ Amount',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ALIASES = [
        'description' => ['itemsdescription', 'itemdescription', 'description'],
        'service_code' => ['servicecode', 'service', 'code'],
        'sr_no' => ['srno', 'itemno', 'itemnumber'],
        'unit' => ['unit', 'uom'],
        'quantity' => ['boqqty', 'boqquantity', 'boq', 'qty', 'quantity'],
        'rate' => ['raters', 'rate', 'unitrate'],
        'amount' => ['boqamount', 'totalamount', 'amount'],
    ];

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BOQ');

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        $sheet->fromArray([
            ['10', '90130546', 'Example BOQ item description', 'SQM', 100, 110],
            ['20', '99992878', 'Example second BOQ item', 'SQM', 50, 150],
        ], null, 'A2');
        $sheet->setCellValue('G2', '=E2*F2');
        $sheet->setCellValue('G3', '=E3*F3');

        $sheet->setCellValue('B5', '10');
        $sheet->setCellValue('C5', 'Landscape and Basement Finishes');

        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F1F5F9');
        $sheet->getStyle('C:C')->getAlignment()->setWrapText(true);
        $sheet->getStyle('E:E')->getNumberFormat()->setFormatCode('0.000');
        $sheet->getStyle('F:G')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->freezePane('A2');

        $widths = [14, 18, 64, 12, 14, 14, 18];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $sheet->getStyle('E:G')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $spreadsheet;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, can_confirm: bool, message: ?string}
     */
    public function parse(string $path): array
    {
        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (Throwable) {
            return [
                'rows' => [],
                'can_confirm' => false,
                'message' => 'The Excel file could not be read.',
            ];
        }

        $header = $this->headerMap($sheet);

        if ($header === null) {
            return [
                'rows' => [],
                'can_confirm' => false,
                'message' => 'The BOQ header row could not be found.',
            ];
        }

        $rows = [];

        for ($row = $header['row'] + 1; $row <= $sheet->getHighestRow(); $row++) {
            $parsed = $this->parseRow($sheet, $row, $header['columns']);

            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        $invalid = collect($rows)->contains(fn (array $row) => $row['status'] === 'invalid');

        return [
            'rows' => $rows,
            'can_confirm' => $rows !== [] && ! $invalid,
            'message' => null,
        ];
    }

    /**
     * @return array{row: int, columns: array<string, int>}|null
     */
    private function headerMap(Worksheet $sheet): ?array
    {
        $highest = min($sheet->getHighestRow(), 40);
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($row = 1; $row <= $highest; $row++) {
            $labels = [];

            for ($column = 1; $column <= $highestColumn; $column++) {
                $compact = $this->compact($this->text($sheet, $column, $row));

                if ($compact !== '') {
                    $labels[$column] = $compact;
                }
            }

            $columns = [];

            foreach (self::ALIASES as $field => $aliases) {
                foreach ($labels as $column => $label) {
                    if (in_array($label, $aliases, true) && ! in_array($column, $columns, true)) {
                        $columns[$field] = $column;
                        break;
                    }
                }
            }

            if (isset($columns['description']) && (isset($columns['quantity']) || isset($columns['rate']))) {
                return ['row' => $row, 'columns' => $columns];
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<string, mixed>|null
     */
    private function parseRow(Worksheet $sheet, int $row, array $columns): ?array
    {
        $sr = $this->text($sheet, $columns['sr_no'] ?? 0, $row);
        $service = $this->text($sheet, $columns['service_code'] ?? 0, $row);
        $description = $this->text($sheet, $columns['description'] ?? 0, $row);
        $unit = $this->text($sheet, $columns['unit'] ?? 0, $row);
        $quantityRaw = $this->raw($sheet, $columns['quantity'] ?? 0, $row);
        $rateRaw = $this->raw($sheet, $columns['rate'] ?? 0, $row);

        if ($this->blank($sr, $service, $description, $unit, $quantityRaw, $rateRaw)) {
            return null;
        }

        if ($this->ignoredTotal($description, $sr)) {
            return null;
        }

        $quantity = $this->number($quantityRaw);
        $rate = $this->number($rateRaw);
        $errors = [];

        $quantityEmpty = $this->emptyNumber($quantityRaw);
        $rateEmpty = $this->emptyNumber($rateRaw);

        if ($description === '' && $quantityEmpty && $rateEmpty) {
            return null;
        }

        if ($description !== '' && $quantityEmpty && $rateEmpty) {
            return [
                'status' => 'header',
                'row_number' => $row,
                'sr_no' => $sr,
                'service_code' => $service,
                'description' => $description,
                'unit' => $unit,
                'quantity' => null,
                'rate' => null,
                'amount' => null,
                'errors' => [],
            ];
        }

        if ($description === '') {
            $errors[] = 'Item Description is missing.';
        }

        if (! $quantity['ok']) {
            $errors[] = 'B.O.Q. must be numeric.';
        }

        if (! $rate['ok']) {
            $errors[] = 'Rate must be numeric.';
        }

        $quantityValue = $quantity['ok'] ? $this->scale($quantity['value'], 3) : null;
        $rateValue = $rate['ok'] ? $this->scale($rate['value'], 2) : null;
        $amount = ($errors === [] && $quantityValue !== null && $rateValue !== null)
            ? Money::itemAmount($quantityValue, $rateValue)
            : null;

        return [
            'status' => $errors === [] ? 'valid' : 'invalid',
            'row_number' => $row,
            'sr_no' => $sr,
            'service_code' => $service,
            'description' => $description,
            'unit' => $unit,
            'quantity' => $quantityValue,
            'rate' => $rateValue,
            'amount' => $amount,
            'errors' => $errors,
        ];
    }

    private function text(Worksheet $sheet, int $column, int $row): string
    {
        if ($column < 1 || $row < 1) {
            return '';
        }

        $value = $this->raw($sheet, $column, $row);

        if ($value === null) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($text);
    }

    private function raw(Worksheet $sheet, int $column, int $row): mixed
    {
        if ($column < 1 || $row < 1) {
            return null;
        }

        $cell = $sheet->getCell([$column, $row]);
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        if (is_string($value) && str_starts_with(trim($value), '=')) {
            try {
                return $cell->getCalculatedValue();
            } catch (Throwable) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @return array{ok: bool, value: mixed}
     */
    private function number(mixed $value): array
    {
        if ($this->emptyNumber($value)) {
            return ['ok' => true, 'value' => null];
        }

        if (is_int($value) || is_float($value)) {
            return $value < 0
                ? ['ok' => false, 'value' => null]
                : ['ok' => true, 'value' => $value];
        }

        $text = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', trim((string) $value));

        if ($text === '' || ! is_numeric($text) || (float) $text < 0) {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => $text];
    }

    private function emptyNumber(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        $text = trim((string) $value);

        return $text === '' || $text === '-' || $text === '—';
    }

    private function scale(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::scale($value, $scale);
    }

    private function blank(string $sr, string $service, string $description, string $unit, mixed $quantity, mixed $rate): bool
    {
        return $sr === '' && $service === '' && $description === '' && $unit === ''
            && $this->emptyNumber($quantity) && $this->emptyNumber($rate);
    }

    private function ignoredTotal(string $description, string $sr): bool
    {
        foreach ([$description, $sr] as $value) {
            $compact = $this->compact($value);

            if ($compact === '') {
                continue;
            }

            if (in_array($compact, ['total', 'totalamount', 'boqtotal', 'gst', 'grandtotal', 'grandtotalamount'], true)) {
                return true;
            }

            if (str_starts_with($compact, 'gstat') || preg_match('/^gst\d/', $compact) === 1) {
                return true;
            }
        }

        return false;
    }

    private function compact(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }
}
