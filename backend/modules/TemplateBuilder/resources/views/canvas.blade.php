<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>Document</title>
    <style>
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
            padding: 0;
        }

        table { border-collapse: collapse; width: 100%; }

        img { max-width: 100%; }

        b, strong { font-weight: bold; }

        /* Spacing helpers used by the block renderer */
        .tb-divider { border: none; border-top: 1px solid #e5e7eb; margin: 14px 0; }
        .tb-spacer  { width: 100%; }

        .tb-line-row {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .tb-line-row-last { page-break-after: avoid !important; }
        .tb-document-totals {
            padding-top: 12mm;
            page-break-inside: avoid !important;
        }
        .tb-document-footer {
            width: 100%;
        }

        @media screen {
            body {
                padding: {{ ($margin['top'] ?? 15) * 3.78 }}px {{ ($margin['right'] ?? 12) * 3.78 }}px {{ ($margin['bottom'] ?? 15) * 3.78 }}px {{ ($margin['left'] ?? 12) * 3.78 }}px;
            }
            /* PDF measurement keeps Footer first in the DOM. Screen previews
               reverse the pair so users see the intended Totals → Footer order. */
            .tb-final-summary {
                display: flex !important;
                flex-direction: column-reverse !important;
            }
            .tb-document-footer { position: static; margin-top: 20px; }
        }

        @if (!empty($css))
            {!! $css !!}
        @endif

        /* Keep the physical pagination safety band authoritative even when a
           template supplies custom @page CSS. */
        @page { margin: {{ ($margin['top'] ?? 15) }}mm {{ ($margin['right'] ?? 12) }}mm {{ ($margin['bottom'] ?? 15) + 40 }}mm {{ ($margin['left'] ?? 12) }}mm; }
    </style>
</head>
<body>
    {!! $body !!}
</body>
</html>
