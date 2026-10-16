<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportSpreadsheet
{
    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>|null  $sections
     */
    public function download(
        string $title,
        array $columns,
        array $rows,
        ?array $totals = null,
        ?array $sections = null,
        string $filename = 'report.xlsx',
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $title) ?: 'Report', 0, 31));

        $rowIndex = 1;
        $sheet->setCellValue("A{$rowIndex}", $title);
        $rowIndex += 2;

        if ($sections !== null && $sections !== []) {
            foreach ($sections as $section) {
                $sheet->setCellValue("A{$rowIndex}", (string) ($section['title'] ?? 'Section'));
                $rowIndex++;
                $sectionColumns = $section['columns'] ?? [];
                $rowIndex = $this->writeTable($sheet, $rowIndex, $sectionColumns, $section['rows'] ?? []);
                $rowIndex++;
            }
        } else {
            $rowIndex = $this->writeTable($sheet, $rowIndex, $columns, $rows);

            if ($totals !== null && $totals !== []) {
                $rowIndex++;
                $sheet->setCellValue("A{$rowIndex}", 'Totals');
                $rowIndex++;
                foreach ($totals as $key => $value) {
                    $sheet->setCellValue("A{$rowIndex}", (string) $key);
                    $sheet->setCellValue("B{$rowIndex}", (string) $value);
                    $rowIndex++;
                }
            }
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeTable(Worksheet $sheet, int $rowIndex, array $columns, array $rows): int
    {
        if ($columns === []) {
            return $rowIndex;
        }

        $colIndex = 1;
        foreach ($columns as $column) {
            $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, (string) ($column['label'] ?? $column['key'] ?? ''));
            $colIndex++;
        }
        $rowIndex++;

        foreach ($rows as $row) {
            $colIndex = 1;
            foreach ($columns as $column) {
                $key = (string) ($column['key'] ?? '');
                $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, (string) ($row[$key] ?? ''));
                $colIndex++;
            }
            $rowIndex++;
        }

        return $rowIndex;
    }
}
