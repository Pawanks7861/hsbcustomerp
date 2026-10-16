@php
    $displayNumber = ltrim(trim((string) ($number ?? '')), '#');
    $subjectText = trim((string) ($subject ?? ''));
    $dateLabel = trim((string) ($dateLabel ?? 'Date'));
    $relatedLabel = trim((string) ($relatedLabel ?? ''));
    $relatedValue = ltrim(trim((string) ($relatedValue ?? '')), '#');
    $partyData = is_array($party ?? null) ? $party : [];
    $partyLines = $partyData['lines'] ?? [];
    $partyName = trim((string) ($partyData['name'] ?? ''));
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
                        <span class="company-label">{{ $line['label'] }} :</span>
                    @endif
                    {{ $line['text'] }}
                </div>
            @endforeach
        </td>
        <td class="document-column">
            <div class="doc-title">{{ $title }}</div>
            @if($displayNumber !== '' || $subjectText !== '')
                <div class="doc-number">{{ $displayNumber }}@if($displayNumber !== '' && $subjectText !== '') - @endif{{ $subjectText }}</div>
            @endif
            @if(($revision ?? null) !== null && $revision !== '')
                <div class="line">Revision: REV {{ $revision }}</div>
            @endif
            @if(! empty($date))
                <div class="line">{{ $dateLabel }}: {{ $date }}</div>
            @endif
            @include('pdf.partials.status-badge')
            @if($partyName !== '' || $partyLines !== [])
                <div class="party">
                    @if(! empty($partyData['label']))
                        <div class="party-label">{{ $partyData['label'] }}</div>
                    @endif
                    @if($partyName !== '')
                        <div class="party-name">{{ $partyName }}</div>
                    @endif
                    @foreach($partyLines as $line)
                        <div class="party-line">
                            @if(! empty($line['label']))
                                <span class="company-label">{{ $line['label'] }}:</span>
                            @endif
                            {{ $line['text'] }}
                        </div>
                    @endforeach
                </div>
            @endif
        </td>
    </tr>
    @if(! empty($projectName) || $relatedValue !== '')
        <tr>
            <td class="header-foot">
                @if(! empty($projectName))
                    <span class="company-label">Project:</span> {{ $projectName }}
                @endif
            </td>
            <td class="header-foot document-column">
                @if($relatedValue !== '')
                    @if($relatedLabel !== '')
                        <span class="company-label">{{ $relatedLabel }}:</span>
                    @endif
                    {{ $relatedValue }}
                @endif
            </td>
        </tr>
    @endif
</table>
