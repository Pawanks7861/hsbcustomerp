@extends('pdf.layout')

@section('content')
    <h2 style="margin:0 0 8px;font-size:16px;">{{ $title }}</h2>

    @if(! empty($filters))
        <table class="data" style="margin-bottom:12px;">
            <tbody>
                @foreach($filters as $filter)
                    <tr>
                        <td style="width:120px;"><strong>{{ $filter['label'] }}</strong></td>
                        <td>{{ $filter['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(! empty($sections))
        @foreach($sections as $section)
            <h3 style="margin:16px 0 6px;font-size:13px;">{{ $section['title'] ?? 'Section' }}</h3>
            @include('pdf.partials.report-table', ['columns' => $section['columns'] ?? [], 'rows' => $section['rows'] ?? []])
        @endforeach
    @else
        @include('pdf.partials.report-table', ['columns' => $columns ?? [], 'rows' => $rows ?? []])
    @endif

    @if(! empty($totals))
        <table class="data" style="margin-top:12px;">
            <tbody>
                @foreach($totals as $key => $value)
                    <tr>
                        <td><strong>{{ str_replace('_', ' ', ucfirst($key)) }}</strong></td>
                        <td class="num">{{ is_numeric($value) ? \App\Support\PdfFormat::money($value) : $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
