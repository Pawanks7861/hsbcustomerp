<?php

namespace App\Imports\Definitions;

use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\CreditNote;

class CreditNotesImport extends DebitNotesImport
{
    public function key(): string
    {
        return 'credit_notes';
    }

    public function label(): string
    {
        return 'Credit Notes (draft)';
    }

    public function permission(): string
    {
        return 'credit_notes.import';
    }

    public function instructions(): string
    {
        return 'Creates DRAFT credit notes only. Approval and ledger impact follow the existing workflow. Does not auto-link invoices/POs.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        return $this->validateNoteRow($rowNumber, $raw, $context);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        return $this->commitNote($row, $context, CreditNote::class, 'CN');
    }
}
