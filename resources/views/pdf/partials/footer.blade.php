<div class="footer">
    {{ ltrim(trim((string) ($number ?? '')), '#') }}@if(($revision ?? null) !== null && $revision !== '') · REV {{ $revision }}@endif · Generated {{ $generatedAt }}
    @if(! empty($footerNote))
        <br>{{ $footerNote }}
    @endif
</div>
