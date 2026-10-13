@extends('pdf.layout')

@section('content')
    <table class="data dense">
        <thead>
            <tr>
                <th class="center">#</th>
                <th>BOQ Item</th>
                <th>Code</th>
                <th class="center">Unit</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document['items'] as $index => $line)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="wrap">
                        {{ $line['description'] }}
                        <div class="muted">{{ $line['boq_number'] }}@if($line['revision_number'] !== null) · REV {{ $line['revision_number'] }}@endif</div>
                    </td>
                    <td>{{ $line['service_code'] ?: $line['item_number'] ?: '—' }}</td>
                    <td class="center">{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity']) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money($line['rate']) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-layout">
        <tr>
            <td class="words">
                <div class="k">Amount in words</div>
                <div class="v">{{ \App\Support\PdfFormat::amountInWords($document['grand_total']) }}</div>
            </td>
            <td>
                <table class="data">
                    <tr>
                        <td>Subtotal</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['subtotal']) }}</td>
                    </tr>
                    <tr>
                        <td>GST @ {{ rtrim(rtrim((string) $document['gst_percentage'], '0'), '.') }}%</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['gst_amount']) }}</td>
                    </tr>
                    <tr>
                        <td>Grand total (INR)</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['grand_total']) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if(! empty($document['scope']))
        <div class="block">
            <div class="block-title">Scope of work</div>
            <div style="padding: 6pt;"><p class="prose">{{ $document['scope'] }}</p></div>
        </div>
    @endif

    @if(! empty($document['completion_date']))
        <div class="block">
            <div class="block-title">Completion timeline</div>
            <div style="padding: 6pt;"><p class="prose">Completion date: {{ \App\Support\PdfFormat::date($document['completion_date']) }}</p></div>
        </div>
    @endif

    @if(! empty($document['payment_terms']))
        <div class="block">
            <div class="block-title">Payment terms</div>
            <div style="padding: 6pt;"><p class="prose">{{ $document['payment_terms'] }}</p></div>
        </div>
    @endif

    @if(! empty($document['terms_conditions']))
        <div class="block">
            <div class="block-title">Terms &amp; Conditions</div>
            <div style="padding: 6pt;"><p class="prose">{{ $document['terms_conditions'] }}</p></div>
        </div>
    @endif

    <table class="sign">
        <tr>
            <td>
                @if(! empty($document['approved_by']))
                    <div class="v">Approved by {{ $document['approved_by'] }}@if(! empty($document['approved_at'])) on {{ \App\Support\PdfFormat::dateTime($document['approved_at']) }}@endif</div>
                @else
                    <div class="k">Approval pending</div>
                @endif
                @if(! empty($document['approval_remarks']))
                    <div class="k">Approval remarks</div>
                    <div class="prose">{{ $document['approval_remarks'] }}</div>
                @endif
                <div class="sign-line">Contractor acceptance and signature</div>
            </td>
            <td>
                <table class="sign-right">
                    <tr>
                        <td>
                            @if($company?->company_name)
                                <div class="v">For {{ $company->company_name }}</div>
                            @endif
                            <div class="sign-line">Authorised signatory</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
