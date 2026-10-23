@php
    $displayNumber = ltrim(trim((string) ($number ?? '')), '#');
    $subjectText = trim((string) ($subject ?? ''));
    $deliveryDate = trim((string) ($deliveryDate ?? ''));
    $relatedValue = ltrim(trim((string) ($relatedValue ?? '')), '#');
    $vendorName = trim((string) ($vendor['name'] ?? ''));
    $shipLines = preg_split('/\R/', (string) ($document['delivery_address'] ?? '')) ?: [];
    $shipLines = array_values(array_filter(array_map('trim', $shipLines)));
@endphp
<table class="header-layout">
    <tr>
        <td class="company-column">
            @if(! empty($logoPath))
                <img class="logo" src="{{ str_replace('\\', '/', $logoPath) }}" width="{{ $logoWidth }}" height="{{ $logoHeight }}" alt="">
            @endif
            @if($company?->company_name)
                <div class="company-name">{{ $company->company_name }}</div>
            @endif
            @foreach($companyLines as $line)
                <div class="company-line">
                    @if(($line['label'] ?? '') !== '')
                        <span class="company-label">{{ $line['label'] === 'GST' ? 'GSTIN' : $line['label'] }}:</span>
                    @endif
                    {{ $line['text'] }}
                </div>
            @endforeach
        </td>
        <td class="document-column">
            <div class="doc-title">PURCHASE ORDER</div>
            @if($displayNumber !== '' || $subjectText !== '')
                <div class="doc-number">{{ $displayNumber }}@if($displayNumber !== '' && $subjectText !== '') - @endif{{ $subjectText }}</div>
            @endif
            @if(! empty($date))
                <div class="line">Order date: {{ $date }}</div>
            @endif
            @if($deliveryDate !== '')
                <div class="line">Delivery by: {{ $deliveryDate }}</div>
            @endif
            @include('pdf.partials.status-badge')
        </td>
    </tr>
</table>

<table class="party-grid">
    <tr>
        <td>
            <div class="party-label">Vendor</div>
            @if($vendorName !== '')
                <div class="party-name">{{ $vendorName }}</div>
            @endif
            @foreach(preg_split('/\R/', (string) ($vendor['address'] ?? '')) ?: [] as $line)
                @if(trim($line) !== '')
                    <div class="party-line">{{ trim($line) }}</div>
                @endif
            @endforeach
            @php
                $place = collect([$vendor['city'] ?? '', $vendor['state'] ?? ''])->map(fn ($value) => trim((string) $value))->filter()->implode(' ');
                $pin = trim((string) ($vendor['pincode'] ?? ''));
            @endphp
            @if($place !== '')
                <div class="party-line">{{ $place }}</div>
            @endif
            @if($pin !== '')
                <div class="party-line">{{ $pin }}</div>
            @endif
            @if(trim((string) ($vendor['gst'] ?? '')) !== '')
                <div class="party-line"><span class="company-label">GSTIN:</span> {{ $vendor['gst'] }}</div>
            @endif
        </td>
        <td>
            <div class="party-label">Bill to</div>
            @if($company?->company_name)
                <div class="party-name">{{ $company->company_name }}</div>
            @endif
            @foreach($companyLines as $line)
                <div class="party-line">
                    @if(($line['label'] ?? '') !== '')
                        <span class="company-label">{{ $line['label'] === 'GST' ? 'GSTIN' : $line['label'] }}:</span>
                    @endif
                    {{ $line['text'] }}
                </div>
            @endforeach
        </td>
        <td>
            <div class="party-label">Ship to</div>
            @if(! empty($projectName))
                <div class="party-name">{{ $projectName }}</div>
            @endif
            @foreach($shipLines as $line)
                <div class="party-line">{{ $line }}</div>
            @endforeach
        </td>
    </tr>
</table>

<table class="header-layout po-meta">
    <tr>
        <td class="header-foot">
            @if(! empty($projectName))
                <span class="company-label">Project:</span> {{ $projectName }}
            @endif
        </td>
        <td class="header-foot document-column">
            @if($relatedValue !== '')
                <span class="company-label">Purchase Request:</span> {{ $relatedValue }}
            @endif
        </td>
    </tr>
    @if(trim((string) ($placeOfSupply ?? '')) !== '' || trim((string) ($taxLabel ?? '')) !== '')
        <tr>
            <td class="header-foot" colspan="2">
                @if(trim((string) ($placeOfSupply ?? '')) !== '')
                    <span class="company-label">Place of supply:</span> {{ $placeOfSupply }}
                @endif
                @if(trim((string) ($taxLabel ?? '')) !== '')
                    <span class="meta-gap"><span class="company-label">Tax:</span> {{ $taxLabel }}</span>
                @endif
            </td>
        </tr>
    @endif
</table>
