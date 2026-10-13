@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Delivery', 'cells' => array_values(array_filter([
        ['label' => 'Project', 'value' => trim(($projectCode ?? '').' '.($projectName ?? ''))],
        ['label' => 'Challan No.', 'value' => $receipt['challan_number'] ?? ''],
        ['label' => 'Challan Date', 'value' => \App\Support\PdfFormat::date($receipt['challan_date'] ?? null)],
        ['label' => 'Vehicle', 'value' => $receipt['vehicle_number'] ?? ''],
        ['label' => 'Received By', 'value' => $receipt['received_by'] ?? ''],
    ], fn ($cell) => filled($cell['value'])))])

    <table class="data">
        <thead>
            <tr>
                <th class="center">Sr.</th>
                <th>PO Item / Description</th>
                <th>Inventory Material</th>
                <th class="center">Unit</th>
                <th class="num">Ordered</th>
                <th class="num">Previously Received</th>
                <th class="num">Received</th>
                <th class="num">Accepted</th>
                <th class="num">Rejected</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receipt['items'] as $index => $line)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="wrap">{{ $line['description'] }}</td>
                    <td class="wrap">{{ $line['material'] ?: '—' }}</td>
                    <td class="center">{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['ordered']) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['previously_received']) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity_received']) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity_accepted']) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity_rejected']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(! empty($receipt['inspection_notes']))
        <div class="block">
            <div class="block-title">Inspection notes</div>
            <div style="padding: 6pt;"><p class="prose">{{ $receipt['inspection_notes'] }}</p></div>
        </div>
    @endif

    @if(! empty($receipt['remarks']))
        <div class="block">
            <div class="block-title">Remarks</div>
            <div style="padding: 6pt;"><p class="prose">{{ $receipt['remarks'] }}</p></div>
        </div>
    @endif

    @include('pdf.partials.approval', [
        'leftLabel' => 'Prepared by',
        'approvedBy' => $receipt['created_by'] ?? '',
        'approvedAt' => $receipt['posted_at'] ?? '',
        'signLabel' => filled($receipt['posted_by'] ?? null) ? 'Posted by '.$receipt['posted_by'] : '',
    ])
@endsection
