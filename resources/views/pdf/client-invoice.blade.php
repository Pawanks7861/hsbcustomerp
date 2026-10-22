@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Invoice', 'cells' => [
        ['label' => 'Due Date', 'value' => \App\Support\PdfFormat::date($invoice['due_date'] ?? null)],
        ['label' => 'Place of Supply', 'value' => $invoice['place_of_supply'] ?? ''],
        ['label' => 'Reference', 'value' => $invoice['reference_number'] ?? ''],
        ['label' => 'Payment Terms', 'value' => $invoice['payment_terms'] ?? ''],
    ]])

    @if(! empty($invoice['items']))
        <table class="data">
            <thead>
                <tr>
                    <th class="center">Sr.</th>
                    <th>Item</th>
                    <th class="center">Unit</th>
                    <th class="num">Qty</th>
                    <th class="num">Rate</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice['items'] as $index => $line)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td class="wrap">
                            {{ $line['description'] }}
                            @if(! empty($line['item_number']))
                                <div class="muted">{{ $line['item_number'] }}</div>
                            @endif
                        </td>
                        <td class="center">{{ $line['unit'] }}</td>
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
                <td>Subtotal</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['subtotal']) }}</td>
            </tr>
            <tr>
                <td>Discount</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['discount_total']) }}</td>
            </tr>
            <tr>
                <td>Taxable Amount</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['taxable_value']) }}</td>
            </tr>
            <tr>
                <td>CGST{{ ! empty($invoice['cgst_percentage']) ? ' ('.rtrim(rtrim((string) $invoice['cgst_percentage'], '0'), '.').'%)' : '' }}</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['cgst_amount'] ?? '0.00') }}</td>
            </tr>
            <tr>
                <td>SGST{{ ! empty($invoice['sgst_percentage']) ? ' ('.rtrim(rtrim((string) $invoice['sgst_percentage'], '0'), '.').'%)' : '' }}</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['sgst_amount'] ?? '0.00') }}</td>
            </tr>
            <tr>
                <td>GST{{ $invoice['gst_percentage'] !== null ? ' ('.rtrim(rtrim((string) $invoice['gst_percentage'], '0'), '.').'%)' : '' }}</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['gst_amount']) }}</td>
            </tr>
            <tr>
                <td>Round Off</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['round_off'] ?? '0.00') }}</td>
            </tr>
            <tr>
                <td>Total Amount</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['grand_total']) }}</td>
            </tr>
            <tr>
                <td>Amount Received</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['paid']) }}</td>
            </tr>
            <tr>
                <td>Outstanding</td>
                <td class="num">{{ \App\Support\PdfFormat::money($invoice['outstanding']) }}</td>
            </tr>
        </tbody>
    </table>

    @if(! empty($invoice['description']))
        <div class="block">
            <div class="block-title">Description</div>
            <div style="padding: 6pt;"><p class="prose">{{ $invoice['description'] }}</p></div>
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
        'approvedBy' => ($invoice['approval_status'] ?? '') === 'approved' ? ($invoice['approved_by'] ?? '') : '',
        'approvedAt' => ($invoice['approval_status'] ?? '') === 'approved' ? \App\Support\PdfFormat::date($invoice['approved_at'] ?? null) : '',
    ])
@endsection
