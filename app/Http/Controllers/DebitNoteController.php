<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesProjectVendorNotes;
use App\Models\DebitNote;

class DebitNoteController extends Controller
{
    use HandlesProjectVendorNotes;

    protected function notePermissionPrefix(): string
    {
        return 'debit_notes';
    }

    protected function noteModelClass(): string
    {
        return DebitNote::class;
    }

    protected function noteNumberPrefix(): string
    {
        return 'DN';
    }

    protected function inertiaFolder(): string
    {
        return 'DebitNotes';
    }

    protected function pdfView(): string
    {
        return 'pdf.debit-note';
    }

    protected function routePrefix(): string
    {
        return 'projects.debit-notes';
    }
}
