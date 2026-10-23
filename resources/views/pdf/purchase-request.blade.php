@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Request', 'cells' => [
        ['label' => 'Required Date', 'value' => \App\Support\PdfFormat::date($document['required_date'] ?? null)],
        ['label' => 'Requested By', 'value' => $document['requested_by'] ?? ''],
    ]])

    <table class="data">
        <thead>
            <tr>
                <th class="center" style="width: 8%;">Sr.</th>
                <th>Item</th>
                <th class="center" style="width: 12%;">Unit</th>
                <th class="num" style="width: 14%;">Quantity</th>
                <th style="width: 24%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document['items'] as $index => $line)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="wrap">
                        {{ $line['name'] ?: $line['description'] }}
                        @if(! empty($line['description']) && ($line['name'] ?? '') !== '' && $line['description'] !== $line['name'])
                            <div>{{ $line['description'] }}</div>
                        @endif
                        @if(! empty($line['service_code']))
                            <div class="muted">{{ $line['service_code'] }}</div>
                        @endif
                    </td>
                    <td class="center">{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity']) }}</td>
                    <td class="wrap">{{ $line['remarks'] ?: '' }}</td>
                </tr>
            @endforeach
        </tbody>
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
        'note' => $document['rejection_reason'] ?: ($document['over_quantity_approval_note'] ?? null),
        'noteLabel' => ! empty($document['rejection_reason']) ? 'Rejection reason' : 'Approval Note',
        'signLabel' => 'Authorized Signatory',
    ])
    @if(! empty($document['rejected_by']))
        <div class="muted">Rejected by {{ $document['rejected_by'] }} on {{ \App\Support\PdfFormat::dateTime($document['rejected_at'] ?? null) }}</div>
    @endif
@endsection
