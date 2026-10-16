@extends('pdf.layout')

@section('content')
    @include('pdf.partials.field-grid', ['heading' => 'Expense Voucher', 'cells' => [
        ['label' => 'Date', 'value' => \App\Support\PdfFormat::date($expense['date'] ?? null)],
        ['label' => 'Category', 'value' => $expense['category_name'] ?? ''],
        ['label' => 'Project', 'value' => $expense['project_name'] ?? 'Company'],
        ['label' => 'Payee', 'value' => $expense['payee'] ?? '—'],
        ['label' => 'Vendor', 'value' => $expense['vendor_name'] ?? '—'],
        ['label' => 'Payment mode', 'value' => $expense['payment_mode'] ?? '—'],
        ['label' => 'Reference', 'value' => $expense['reference_number'] ?? '—'],
        ['label' => 'Payment status', 'value' => $expense['payment_status'] ?? ''],
    ]])

    @if(! empty($expense['description']))
        <p class="mt"><strong>Description:</strong> {{ $expense['description'] }}</p>
    @endif

    <table class="data">
        <tbody>
            <tr><td>Amount</td><td class="num">{{ \App\Support\PdfFormat::money($expense['amount']) }}</td></tr>
            <tr><td>Tax</td><td class="num">{{ \App\Support\PdfFormat::money($expense['tax_amount']) }}</td></tr>
            <tr><td>Round off</td><td class="num">{{ \App\Support\PdfFormat::money($expense['round_off']) }}</td></tr>
            <tr><td><strong>Total</strong></td><td class="num"><strong>{{ \App\Support\PdfFormat::money($expense['total_amount']) }}</strong></td></tr>
        </tbody>
    </table>

    @if(! empty($expense['notes']))
        <p class="mt"><strong>Notes:</strong> {{ $expense['notes'] }}</p>
    @endif
@endsection
