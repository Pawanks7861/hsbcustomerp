@extends('pdf.layout')

@section('content')
    @php
        $note = $transfer;
    @endphp
    @include('pdf.partials.field-grid', ['heading' => 'Transfer', 'cells' => array_values(array_filter([
        ['label' => 'Source', 'value' => $note->sourceLocation?->name],
        ['label' => 'Source project', 'value' => $note->sourceProject?->project_name],
        ['label' => 'Destination', 'value' => $note->destinationLocation?->name],
        ['label' => 'Destination project', 'value' => $note->destinationProject?->project_name],
        ['label' => 'Expected arrival', 'value' => \App\Support\PdfFormat::date($note->expected_arrival_date)],
        ['label' => 'Vehicle', 'value' => $note->vehicle_number],
        ['label' => 'Driver', 'value' => $note->driver_name],
    ], fn ($cell) => filled($cell['value'])))])

    <table class="data">
        <thead>
            <tr>
                <th class="center">Sr.</th>
                <th>Material code</th>
                <th>Description</th>
                <th class="center">Unit</th>
                <th class="num">Dispatched</th>
                <th class="num">Accepted</th>
                <th class="num">Damaged / Short</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($note->items as $index => $line)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $line->material?->material_code }}</td>
                    <td class="wrap">{{ $line->material?->name }}</td>
                    <td class="center">{{ $line->unit }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line->quantity) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty($line->quantity_accepted) }}</td>
                    <td class="num">{{ \App\Support\PdfFormat::qty((float) $line->quantity_damaged + (float) $line->quantity_short) }}</td>
                    <td class="wrap">{{ $line->remarks }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($note->remarks)
        <p class="prose">{{ $note->remarks }}</p>
    @endif
    @if($note->rejection_reason)
        <p class="prose">Rejected: {{ $note->rejection_reason }}</p>
    @endif
    @if($note->cancellation_reason)
        <p class="prose">Cancelled: {{ $note->cancellation_reason }}</p>
    @endif

    @include('pdf.partials.approval', [
        'leftLabel' => 'Prepared by',
        'approvedBy' => $note->requester?->name,
        'approvedAt' => \App\Support\PdfFormat::dateTime($note->created_at?->toDateTimeString()),
        'signLabel' => $note->approver ? 'Approved by '.$note->approver->name : '',
    ])

    @if($note->dispatcher || $note->receiver)
        <table class="sign">
            <tr>
                <td>
                    @if($note->dispatcher)
                        <div class="k">Dispatched by</div>
                        <div class="v">{{ $note->dispatcher->name }}</div>
                        <div class="v">{{ \App\Support\PdfFormat::dateTime($note->dispatched_at?->toDateTimeString()) }}</div>
                    @endif
                </td>
                <td>
                    @if($note->receiver)
                        <div class="k">Received by</div>
                        <div class="v">{{ $note->receiver->name }}</div>
                        <div class="v">{{ \App\Support\PdfFormat::dateTime($note->received_at?->toDateTimeString()) }}</div>
                    @endif
                </td>
            </tr>
        </table>
    @endif
@endsection
