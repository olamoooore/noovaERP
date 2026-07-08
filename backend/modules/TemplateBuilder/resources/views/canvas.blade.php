<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>Document</title>
    <style>
        @page { margin: {{ ($margin['top'] ?? 15) }}mm {{ ($margin['right'] ?? 12) }}mm {{ ($margin['bottom'] ?? 15) }}mm {{ ($margin['left'] ?? 12) }}mm; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: '{{ $font }}', 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #1f2937;
        }

        body { -webkit-font-smoothing: antialiased; }

        table { border-collapse: collapse; width: 100%; }

        img { max-width: 100%; }

        b, strong { font-weight: bold; }

        @if (!empty($css))
            {{ $css }}
        @endif
    </style>
</head>
<body>
    {!! $body !!}
</body>
</html>
