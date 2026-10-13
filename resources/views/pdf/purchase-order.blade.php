@extends('pdf.layout')

@section('content')
    <table class="data dense">
        <thead>
            <tr>
                <th class="center">#</th>
                <th>Item</th>
                <th>HSN/SAC</th>
                <th class="num">Qty</th>
                <th class="center">Unit</th>
                <th class="num">Rate</th>
                <th class="num">Disc %</th>
                <th class="num">Taxable</th>
                <th class="num">CGST %</th>
                <th class="num">CGST</th>
                <th class="num">SGST %</th>
                <th class="num">SGST</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document['items'] as $index => $line)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="wrap">
                        {{ $line['description'] }}
                        @if(! empty($line['service_code']))
                            <div class="muted">{{ $line['service_code'] }}</div>
                        @endif
                    </td>
                    <td>{{ $line['hsn_sac'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity']) }}</td>
                    <td class="center">{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money($line['rate']) }}</td>
                    <td class="num">{{ rtrim(rtrim((string) ($line['discount_percentage'] ?? '0'), '0'), '.') }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money($line['taxable_amount'] ?? $line['amount']) }}</td>
                    <td class="num">{{ rtrim(rtrim((string) ($document['cgst_percentage'] ?? '0'), '0'), '.') }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money($line['cgst_amount'] ?? '0') }}</td>
                    <td class="num">{{ rtrim(rtrim((string) ($document['sgst_percentage'] ?? '0'), '0'), '.') }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money($line['sgst_amount'] ?? '0') }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::money(($line['line_total'] ?? '') !== '' && (float) $line['line_total'] != 0 ? $line['line_total'] : $line['amount']) }}</td>
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
                        <td>Sub total</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['subtotal']) }}</td>
                    </tr>
                    <tr>
                        <td>Discount</td>
                        <td class="num">{{ \App\Support\PdfFormat::money(((float) ($document['discount_total'] ?? 0)) > 0 ? '-'.$document['discount_total'] : ($document['discount_total'] ?? '0')) }}</td>
                    </tr>
                    <tr>
                        <td>Taxable value</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['taxable_value'] ?? $document['subtotal']) }}</td>
                    </tr>
                    <tr>
                        <td>CGST @ {{ rtrim(rtrim((string) ($document['cgst_percentage'] ?? '0'), '0'), '.') }}%</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['cgst_amount'] ?? '0') }}</td>
                    </tr>
                    <tr>
                        <td>SGST @ {{ rtrim(rtrim((string) ($document['sgst_percentage'] ?? '0'), '0'), '.') }}%</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['sgst_amount'] ?? '0') }}</td>
                    </tr>
                    <tr>
                        <td>Freight</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['freight'] ?? '0') }}</td>
                    </tr>
                    <tr>
                        <td>Round off</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['round_off'] ?? '0') }}</td>
                    </tr>
                    <tr>
                        <td>Grand total (INR)</td>
                        <td class="num">{{ \App\Support\PdfFormat::money($document['grand_total']) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if(! empty($document['terms_conditions']))
        <div class="block">
            <div class="block-title">Terms &amp; Conditions</div>
            <div style="padding: 6pt;"><p class="prose">{{ $document['terms_conditions'] }}</p></div>
        </div>
    @endif

    @if(! empty($document['remarks']))
        <div class="block">
            <div class="block-title">Remarks</div>
            <div style="padding: 6pt;"><p class="prose">{{ $document['remarks'] }}</p></div>
        </div>
    @endif

    <table class="sign">
        <tr>
            <td>
                @if(! empty($document['approved_by']))
                    <div class="v">Approved by {{ $document['approved_by'] }}@if(! empty($document['approved_at'])) on {{ \App\Support\PdfFormat::dateTime($document['approved_at']) }}@endif</div>
                @endif
                @if(! empty($document['rejection_reason']))
                    <div class="k">Rejection reason</div>
                    <div class="prose">{{ $document['rejection_reason'] }}</div>
                @endif
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
