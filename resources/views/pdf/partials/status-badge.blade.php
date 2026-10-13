@php
    $raw = strtolower(trim((string) ($status ?? '')));
    $label = strtoupper(str_replace('_', ' ', $raw));
@endphp
@if($label !== '')
    <div><span class="badge badge-{{ $raw }}">{{ $label }}</span></div>
@endif
