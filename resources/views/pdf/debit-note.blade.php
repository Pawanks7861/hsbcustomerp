@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Debit Note', 'cells' => [
        ['label' => 'Date', 'value' => \App\Support\PdfFormat::date($note['date'] ?? null)],
        ['label' => 'Vendor', 'value' => $note['vendor_name'] ?? ''],
        ['label' => 'PO No.', 'value' => $note['po_number'] ?? '—'],
        ['label' => 'Invoice No.', 'value' => $note['invoice_number'] ?? '—'],
        ['label' => 'Reason', 'value' => $note['reason'] ?? ''],
    ]])

    @if(! empty($note['items']))
        <table class="data dense">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="center">Unit</th>
                    <th class="num">Qty</th>
                    <th class="num">Rate</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($note['items'] as $index => $line)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td class="wrap">{{ $line['description'] }}</td>
                        <td class="center">{{ $line['unit'] ?: '—' }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity']) }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($line['rate']) }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

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
