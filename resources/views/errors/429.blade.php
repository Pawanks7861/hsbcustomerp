<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>429 — Too Many Requests · {{ config('app.name', 'HSB Projects') }}</title>
    <style>
        body { font-family: Inter, system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #0f172a; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.5rem; margin-bottom: 0.75rem; }
        p { color: #475569; line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <h1>Too many requests</h1>
        <p>{{ $message }}</p>
    </main>
</body>
</html>
