@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Invoice', 'cells' => [
        ['label' => 'Due Date', 'value' => \App\Support\PdfFormat::date($invoice['due_date'] ?? null)],
    ]])

    @if(! empty($invoice['items']))
        <table class="data">
            <thead>
                <tr>
                    <th class="center">Sr.</th>
                    <th>Item</th>
                    <th class="center">Unit</th>
                    <th class="num">PO Qty</th>
                    <th class="num">Previous</th>
                    <th class="num">Invoice Qty</th>
                    <th class="num">Rate</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice['items'] as $index => $line)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td class="wrap">{{ $line['description'] }}</td>
                        <td class="center">{{ $line['unit'] }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::qty($line['po_quantity']) }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::qty($line['previously_invoiced']) }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity']) }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($line['rate']) }}</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($line['taxable_amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="data">
        <thead>
            <tr>
                <th>Financial summary</th>
                <th class="num" style="width: 28%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Invoice Amount</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['invoice_amount']) }}</td>
            </tr>
            <tr>
                <td>Tax Amount{{ $invoice['tax_percentage'] !== null ? ' (GST '.rtrim(rtrim($invoice['tax_percentage'], '0'), '.').'%)' : '' }}</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['tax_amount']) }}</td>
            </tr>
            <tr>
                <td>Round Off</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['round_off'] ?? '0.00') }}</td>
            </tr>
            <tr>
                <td>Total Amount</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['total_amount']) }}</td>
            </tr>
            <tr>
                <td>Invoice Payments</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['paid']) }}</td>
            </tr>
            <tr>
                <td>Advance Adjusted</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['advance_applied']) }}</td>
            </tr>
            <tr>
                <td>Outstanding</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['outstanding']) }}</td>
            </tr>
        </tbody>
    </table>

    @if(! empty($invoice['has_attachment']))
        <div class="block">
            <div class="block-title">Attachment</div>
            <div style="padding: 6pt;">Attachment Available</div>
        </div>
    @endif

    @if(! empty($invoice['remarks']))
        <div class="block">
            <div class="block-title">Remarks</div>
            <div style="padding: 6pt;"><p class="prose">{{ $invoice['remarks'] }}</p></div>
        </div>
    @endif

    @include('pdf.partials.approval', [
        'signLabel' => 'Authorized Signatory',
    ])
@endsection
