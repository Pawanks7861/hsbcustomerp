@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Receipt', 'cells' => [
        ['label' => 'Receipt Type', 'value' => $receipt['type'] ?? ''],
        ['label' => 'Received By', 'value' => $receipt['received_by'] ?? ''],
    ]])

    <table class="data">
        <thead>
            <tr>
                <th class="center" style="width: 8%;">Sr.</th>
                <th>Description</th>
                <th class="center" style="width: 12%;">Unit</th>
                <th class="num" style="width: 18%;">Quantity Received</th>
                <th style="width: 22%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receipt['items'] as $index => $line)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="wrap">{{ $line['description'] }}</td>
                    <td class="center">{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line['quantity_received']) }}</td>
                    <td class="wrap">{{ $line['remarks'] ?: '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(! empty($receipt['remarks']))
        <div class="block">
            <div class="block-title">Remarks</div>
            <div style="padding: 6pt;"><p class="prose">{{ $receipt['remarks'] }}</p></div>
        </div>
    @endif

    @include('pdf.partials.approval', [
        'leftLabel' => 'Received by',
        'approvedBy' => $receipt['received_by'] ?? null,
        'signLabel' => 'Received by',
    ])
@endsection
