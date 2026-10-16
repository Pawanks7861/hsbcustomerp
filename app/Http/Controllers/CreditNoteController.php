<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesProjectVendorNotes;
use App\Models\CreditNote;

class CreditNoteController extends Controller
{
    use HandlesProjectVendorNotes;

    protected function notePermissionPrefix(): string
    {
        return 'credit_notes';
    }

    protected function noteModelClass(): string
    {
        return CreditNote::class;
    }

    protected function noteNumberPrefix(): string
    {
        return 'CN';
    }

    protected function inertiaFolder(): string
    {
        return 'CreditNotes';
    }

    protected function pdfView(): string
    {
        return 'pdf.credit-note';
    }

    protected function routePrefix(): string
    {
        return 'projects.credit-notes';
    }
}
