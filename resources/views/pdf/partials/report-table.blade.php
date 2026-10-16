@if(! empty($columns))
    <table class="data">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th @if(($column['align'] ?? '') === 'right') style="text-align:right;" @endif>{{ $column['label'] ?? $column['key'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        @php
                            $key = $column['key'] ?? '';
                            $cell = $row[$key] ?? '';
                            if (! empty($column['money']) && is_numeric($cell)) {
                                $cell = \App\Support\PdfFormat::money($cell);
                            }
                        @endphp
                        <td @if(($column['align'] ?? '') === 'right') class="num" @endif>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
