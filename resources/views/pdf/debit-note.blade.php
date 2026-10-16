@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Debit Note', 'cells' => [
        ['label' => 'Date', 'value' => \App\Support\PdfFormat::date($note['date'] ?? null)],
        ['label' => 'Vendor', 'value' => $note['vendor_name'] ?? ''],
        ['label' => 'PO No.', 'value' => $note['po_number'] ?? '—'],
        ['label' => 'Invoice No.', 'value' => $note['invoice_number'] ?? '—'],
        ['label' => 'Reason', 'value' => $note['reason'] ?? ''],
    ]])

    <table class="data">
        <tbody>
            <tr><td>Subtotal</td><td class="num">{{ \App\Support\PdfFormat::money($note['subtotal']) }}</td></tr>
            <tr><td>Tax</td><td class="num">{{ \App\Support\PdfFormat::money($note['tax_amount']) }}</td></tr>
            <tr><td>Round off</td><td class="num">{{ \App\Support\PdfFormat::money($note['round_off']) }}</td></tr>
            <tr><td><strong>Total</strong></td><td class="num"><strong>{{ \App\Support\PdfFormat::money($note['total_amount']) }}</strong></td></tr>
        </tbody>
    </table>

    @if(! empty($note['remarks']))
        <p class="mt"><strong>Remarks:</strong> {{ $note['remarks'] }}</p>
    @endif
@endsection
