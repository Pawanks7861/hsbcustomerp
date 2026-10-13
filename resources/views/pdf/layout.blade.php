<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number }}</title>
    @include('pdf.partials.styles')
</head>
<body>
    @include('pdf.partials.footer')
    @include($headerPartial ?? 'pdf.partials.header')
    <main>
        @yield('content')
    </main>
</body>
</html>
