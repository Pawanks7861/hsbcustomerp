@php
    $cells = array_values(array_filter($cells ?? [], function ($cell) {
        $value = trim((string) ($cell['value'] ?? ''));

        return $value !== '';
    }));
@endphp
@if($cells !== [])
    <div class="block">
        @if(! empty($heading))
            <div class="block-title">{{ $heading }}</div>
        @endif
        <table class="fields">
            @foreach(array_chunk($cells, 3) as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>
                            <span class="k">{{ $cell['label'] }}</span>
                            <span class="v">{{ $cell['value'] }}</span>
                        </td>
                    @endforeach
                    @for($pad = count($row); $pad < 3; $pad++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    </div>
@endif
