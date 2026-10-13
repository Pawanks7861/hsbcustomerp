@extends('pdf.layout')

@section('content')
    @php
        $control = $document['quantity_control'] ?? null;
        $unit = $control['unit'] ?? null;
    @endphp

    @include('pdf.partials.field-grid', ['heading' => 'Request', 'cells' => [
        ['label' => 'Required Date', 'value' => \App\Support\PdfFormat::date($document['required_date'] ?? null)],
        ['label' => 'Requested By', 'value' => $document['requested_by'] ?? ''],
        ['label' => 'BOQ', 'value' => $document['boq_title'] ?? ''],
        ['label' => 'BOQ Item', 'value' => $document['boq_item'] ?? ''],
    ]])

    @if($control)
        @if(empty($control['has_quantity']))
            <div class="block">
                <div class="block-title">Quantity Control</div>
                <div style="padding: 6pt;">This BOQ item does not have a quantity defined.</div>
            </div>
        @else
            @include('pdf.partials.field-grid', ['heading' => 'Quantity Control', 'cells' => [
                ['label' => 'BOQ Quantity', 'value' => \App\Support\PdfFormat::qty($control['boq_quantity'] ?? null, $unit)],
                ['label' => 'Previously Requested', 'value' => \App\Support\PdfFormat::qty($control['previous_requested_quantity'] ?? null, $unit)],
                ['label' => 'Requested BOQ Quantity', 'value' => \App\Support\PdfFormat::qty($control['requested_quantity'] ?? null, $unit)],
                ['label' => 'Cumulative Quantity', 'value' => \App\Support\PdfFormat::qty($control['cumulative_quantity'] ?? null, $unit)],
                ['label' => 'Remaining Quantity', 'value' => \App\Support\PdfFormat::qty($control['remaining_before_request'] ?? null, $unit)],
                ['label' => 'Excess Quantity', 'value' => \App\Support\PdfFormat::qty($control['excess_quantity'] ?? null, $unit)],
            ]])
        @endif
        @if(! empty($control['is_exceeded']))
            <div class="alert">BOQ QTY EXCEEDED</div>
        @endif
        @if(! empty($control['over_quantity_approved_by']) || ! empty($control['over_quantity_approval_note']))
            @include('pdf.partials.field-grid', ['heading' => 'Over-quantity approval', 'cells' => [
                ['label' => 'Approved By', 'value' => $control['over_quantity_approved_by'] ?? ''],
                ['label' => 'Approved At', 'value' => \App\Support\PdfFormat::dateTime($control['over_quantity_approved_at'] ?? null)],
                ['label' => 'Approval Note', 'value' => $control['over_quantity_approval_note'] ?? ''],
            ]])
        @endif
    @endif

    <table class="data">
        <thead>
            <tr>
                <th class="center" style="width: 8%;">Sr.</th>
                <th>Description</th>
                <th class="center" style="width: 10%;">Unit</th>
                <th class="num" style="width: 12%;">Quantity</th>
                <th class="num" style="width: 14%;">Estimated Rate</th>
                <th class="num" style="width: 16%;">Estimated Amount</th>
                <th style="width: 16%;">Remarks</th>
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
                    <td class="center">{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity']) }}</td>
                    <td class="num">{{ $line['estimated_rate'] === '' || $line['estimated_rate'] === null ? '—' : \App\Support\PdfFormat::money($line['estimated_rate']) }}</td>
                    <td class="num">{{ $line['estimated_amount'] === null || $line['estimated_amount'] === '' ? '—' : \App\Support\PdfFormat::money($line['estimated_amount']) }}</td>
                    <td class="wrap">{{ $line['remarks'] ?: '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="num">Total Estimated Amount</td>
                <td class="num">{{ \App\Support\PdfFormat::money($document['estimated_total']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    @if(! empty($document['description']))
        <div class="block">
            <div class="block-title">Description</div>
            <div style="padding: 6pt;"><p class="prose">{{ $document['description'] }}</p></div>
        </div>
    @endif

    @include('pdf.partials.approval', [
        'approvedBy' => $document['approved_by'] ?? null,
        'approvedAt' => \App\Support\PdfFormat::dateTime($document['approved_at'] ?? null),
        'note' => $document['rejection_reason'] ?: data_get($control, 'over_quantity_approval_note'),
        'noteLabel' => ! empty($document['rejection_reason']) ? 'Rejection reason' : 'Approval Note',
        'signLabel' => 'Authorized Signatory',
    ])
    @if(! empty($document['rejected_by']))
        <div class="muted">Rejected by {{ $document['rejected_by'] }} on {{ \App\Support\PdfFormat::dateTime($document['rejected_at'] ?? null) }}</div>
    @endif
@endsection
