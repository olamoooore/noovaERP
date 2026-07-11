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

        body {
            -webkit-font-smoothing: antialiased;
            /* Screen-friendly padding that matches @page print margins (mm → px: ×3.78) */
            padding: {{ ($margin['top'] ?? 15) * 3.78 }}px {{ ($margin['right'] ?? 12) * 3.78 }}px {{ ($margin['bottom'] ?? 15) * 3.78 }}px {{ ($margin['left'] ?? 12) * 3.78 }}px;
        }

        table { border-collapse: collapse; width: 100%; }

        img { max-width: 100%; }

        b, strong { font-weight: bold; }

        /* Spacing helpers used by the block renderer */
        .tb-divider { border: none; border-top: 1px solid #e5e7eb; margin: 14px 0; }
        .tb-spacer  { width: 100%; }

        @if (!empty($css))
            {!! $css !!}
        @endif
    </style>
</head>
<body>
    {!! $body !!}
</body>
</html>
