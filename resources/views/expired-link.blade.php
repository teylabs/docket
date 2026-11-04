<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>This link has expired</title>
</head>
<body>
    <main>
        <h1>This link has expired</h1>
        <p>Open the file again from where you found it to get a fresh link.</p>
        <p><a href="{{ $url }}">{{ $label }}</a></p>
    </main>
</body>
</html>
