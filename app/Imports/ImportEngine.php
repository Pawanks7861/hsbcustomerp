<?php

namespace App\Imports;

use App\Imports\Contracts\ImportDefinition;
use App\Imports\Spreadsheet\SpreadsheetIO;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportEngine
{
    public function __construct(
        private SpreadsheetIO $spreadsheet,
        private ImportRegistry $registry,
        private ActivityLogger $activity,
    ) {}

    public function definition(string $module): ImportDefinition
    {
        return $this->registry->get($module);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(
        string $module,
        UploadedFile $file,
        User $user,
        string $mode = DuplicateMode::CREATE_ONLY,
        ?Project $project = null,
        ?int $boqId = null,
    ): array {
        $definition = $this->registry->get($module);
        $this->assertMode($definition, $mode);
        $this->assertFile($file);

        $stored = $file->storeAs(
            'imports/'.$user->id,
            Str::uuid()->toString().'.'.$this->storedExtension($file),
            'local'
        );

        $path = Storage::disk('local')->path($stored);
        $parsed = $this->spreadsheet->read($path, $definition);

        $batch = ImportBatch::query()->create([
            'batch_number' => $this->nextBatchNumber(),
            'module' => $module,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $stored,
            'duplicate_mode' => $mode,
            'status' => ImportBatch::STATUS_PREVIEWED,
            'project_id' => $project?->id,
            'uploaded_by' => $user->id,
            'started_at' => now(),
        ]);

        if (! $parsed['ok']) {
            $batch->update([
                'status' => ImportBatch::STATUS_FAILED,
                'summary' => ['message' => $parsed['message']],
                'completed_at' => now(),
            ]);

            return [
                'batch' => $this->batchPayload($batch),
                'can_confirm' => false,
                'message' => $parsed['message'],
                'rows' => [],
                'counts' => $this->emptyCounts(),
            ];
        }

        $context = new ImportContext($user, $mode, $project, $boqId, $batch->id);
        $outcomes = [];
        $errors = [];

        foreach ($parsed['rows'] as $entry) {
            $outcome = $definition->validateRow($entry['row'], $entry['values'], $context);
            $outcomes[] = $this->serializeOutcome($outcome);

            foreach ($outcome->errors as $message) {
                $errors[] = [
                    'row' => $outcome->rowNumber,
                    'column' => '',
                    'value' => '',
                    'message' => $message,
                ];
            }
        }

        $counts = $this->countOutcomes($outcomes);

        $batch->update([
            'total_rows' => $counts['total'],
            'valid_rows' => $counts['valid'],
            'invalid_rows' => $counts['invalid'],
            'duplicate_rows' => $counts['duplicate'],
            'summary' => ['preview_rows' => array_slice($outcomes, 0, 100)],
            'error_report' => $errors,
        ]);

        $canConfirm = $counts['importable'] > 0 && $counts['invalid'] === 0;

        return [
            'batch' => $this->batchPayload($batch->fresh()),
            'can_confirm' => $canConfirm,
            'message' => $canConfirm
                ? null
                : ($counts['invalid'] > 0
                    ? 'Fix the invalid rows and upload again, or download the error report.'
                    : 'No rows are eligible to import with the selected duplicate mode.'),
            'rows' => array_slice($outcomes, 0, 100),
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(ImportBatch $batch, User $user, ?Project $project = null, ?int $boqId = null): array
    {
        abort_unless($batch->uploaded_by === $user->id, 403);
        abort_unless($batch->status === ImportBatch::STATUS_PREVIEWED, 422);
        abort_unless(is_string($batch->stored_path) && Storage::disk('local')->exists($batch->stored_path), 422);

        $definition = $this->registry->get($batch->module);
        $path = Storage::disk('local')->path($batch->stored_path);
        $parsed = $this->spreadsheet->read($path, $definition);

        if (! $parsed['ok']) {
            $batch->update(['status' => ImportBatch::STATUS_FAILED, 'summary' => ['message' => $parsed['message']], 'completed_at' => now()]);

            return ['ok' => false, 'message' => $parsed['message'], 'batch' => $this->batchPayload($batch->fresh())];
        }

        $context = new ImportContext($user, $batch->duplicate_mode, $project ?? $batch->project, $boqId, $batch->id);
        $outcomes = [];

        foreach ($parsed['rows'] as $entry) {
            $outcomes[] = $definition->validateRow($entry['row'], $entry['values'], $context);
        }

        $invalid = array_filter($outcomes, fn (RowOutcome $row) => $row->status === RowOutcome::INVALID);
        if ($invalid !== []) {
            return [
                'ok' => false,
                'message' => 'Import blocked: validation failed on re-check. Upload a corrected file.',
                'batch' => $this->batchPayload($batch->fresh()),
            ];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        try {
            DB::transaction(function () use ($outcomes, $definition, $context, &$created, &$updated, &$skipped, &$failed, &$errors) {
                foreach ($outcomes as $outcome) {
                    if ($outcome->action === 'skip' || $outcome->action === null) {
                        if ($outcome->status === RowOutcome::DUPLICATE) {
                            $skipped++;
                        }
                        continue;
                    }

                    try {
                        $result = $definition->commit($outcome, $context);
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $errors[] = [
                            'row' => $outcome->rowNumber,
                            'column' => '',
                            'value' => '',
                            'message' => 'Row could not be saved. Check required relationships and unique values.',
                        ];
                        throw $e;
                    }
                }
            });
        } catch (Throwable) {
            $batch->update([
                'status' => ImportBatch::STATUS_FAILED,
                'failed_count' => max(1, $failed),
                'error_report' => $errors !== [] ? $errors : $batch->error_report,
                'completed_at' => now(),
            ]);

            return [
                'ok' => false,
                'message' => 'Import rolled back because one or more rows failed.',
                'batch' => $this->batchPayload($batch->fresh()),
            ];
        }

        $batch->update([
            'status' => ImportBatch::STATUS_COMPLETED,
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'completed_at' => now(),
            'summary' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
            ],
        ]);

        $this->activity->log($user->id, 'import.completed', $batch, [
            'module' => $batch->module,
            'batch_number' => $batch->batch_number,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return [
            'ok' => true,
            'message' => "Import completed: {$created} created, {$updated} updated, {$skipped} skipped.",
            'batch' => $this->batchPayload($batch->fresh()),
        ];
    }

    private function assertFile(UploadedFile $file): void
    {
        abort_unless((int) $file->getSize() <= SpreadsheetIO::MAX_BYTES, 422, 'File exceeds the 10 MB limit.');
        $client = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($client, ['xlsx', 'xls', 'csv'], true), 422, 'Only .xlsx, .xls, and .csv files are supported.');

        $guessed = strtolower((string) ($file->guessExtension() ?: ''));
        abort_if(
            in_array($guessed, ['html', 'htm', 'php', 'phtml', 'js', 'svg', 'exe'], true),
            422,
            'The file contents are not a spreadsheet.'
        );
    }

    private function storedExtension(UploadedFile $file): string
    {
        $client = strtolower($file->getClientOriginalExtension());
        $guessed = strtolower((string) ($file->guessExtension() ?: ''));

        if (in_array($guessed, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return $guessed === 'txt' ? $client : $guessed;
        }

        return $client;
    }

    private function assertMode(ImportDefinition $definition, string $mode): void
    {
        abort_unless(in_array($mode, $definition->supportedModes(), true), 422, 'Unsupported duplicate mode for this module.');
    }

    private function nextBatchNumber(): string
    {
        return 'IMP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }

    /**
     * @return array<string, mixed>
     */
    public function batchPayload(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'module' => $batch->module,
            'original_filename' => $batch->original_filename,
            'duplicate_mode' => $batch->duplicate_mode,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'duplicate_rows' => $batch->duplicate_rows,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'skipped_count' => $batch->skipped_count,
            'failed_count' => $batch->failed_count,
            'summary' => $batch->summary,
            'uploaded_by' => $batch->uploader?->name,
            'created_at' => $batch->created_at?->toDateTimeString(),
            'completed_at' => $batch->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'importable' => 0,
            'skippable' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return array<string, int>
     */
    private function countOutcomes(array $outcomes): array
    {
        $counts = $this->emptyCounts();
        $counts['total'] = count($outcomes);

        foreach ($outcomes as $row) {
            if ($row['status'] === RowOutcome::VALID) {
                $counts['valid']++;
            }
            if ($row['status'] === RowOutcome::INVALID) {
                $counts['invalid']++;
            }
            if ($row['status'] === RowOutcome::DUPLICATE) {
                $counts['duplicate']++;
            }
            if (in_array($row['action'], ['create', 'update'], true)) {
                $counts['importable']++;
            }
            if ($row['action'] === 'skip') {
                $counts['skippable']++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOutcome(RowOutcome $outcome): array
    {
        return [
            'row' => $outcome->rowNumber,
            'status' => $outcome->status,
            'action' => $outcome->action,
            'match_key' => $outcome->matchKey,
            'existing_id' => $outcome->existingId,
            'errors' => $outcome->errors,
            'data' => $outcome->data,
        ];
    }
}
