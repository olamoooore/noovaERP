<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Service Report — {{ $workOrder->reference }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1f2937; background: #fff; }

        /* Header */
        .header { background: #1e3a5f; color: #fff; padding: 24px 32px; display: flex; justify-content: space-between; align-items: flex-start; }
        .header h1 { font-size: 20px; font-weight: 700; letter-spacing: 0.5px; }
        .header .sub { font-size: 11px; opacity: 0.8; margin-top: 4px; }
        .ref-badge { background: rgba(255,255,255,0.15); padding: 6px 14px; border-radius: 6px; font-size: 14px; font-weight: 700; }

        /* Section */
        .section { padding: 20px 32px; border-bottom: 1px solid #e5e7eb; }
        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 12px; }

        /* Two-column grid */
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; }
        .field-label { font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        .field-value { font-size: 12px; color: #111827; }

        /* Status pill */
        .status { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-invoiced   { background: #e0e7ff; color: #3730a3; }
        .status-in_progress{ background: #fef3c7; color: #92400e; }

        /* Priority pill */
        .priority { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
        .priority-urgent { background: #fee2e2; color: #991b1b; }
        .priority-high   { background: #fed7aa; color: #92400e; }
        .priority-normal { background: #dbeafe; color: #1e40af; }
        .priority-low    { background: #f3f4f6; color: #374151; }

        /* Worksheet table */
        .ws-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .ws-table th { background: #f9fafb; text-align: left; padding: 8px 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border: 1px solid #e5e7eb; }
        .ws-table td { padding: 8px 12px; border: 1px solid #e5e7eb; vertical-align: top; }
        .ws-table tr:nth-child(even) td { background: #f9fafb; }

        /* Signature area */
        .sig-block { display: inline-block; border: 1px solid #d1d5db; border-radius: 8px; padding: 12px; text-align: center; width: 48%; margin-right: 3%; }
        .sig-block:last-child { margin-right: 0; }
        .sig-img { max-width: 200px; max-height: 80px; display: block; margin: 0 auto 8px; }
        .sig-line { border-top: 1px solid #374151; margin: 8px 0; }
        .sig-label { font-size: 10px; color: #6b7280; }
        .sig-timestamp { font-size: 9px; color: #9ca3af; margin-top: 2px; }

        /* Footer */
        .footer { padding: 16px 32px; background: #f9fafb; text-align: center; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>

{{-- ── Header ─────────────────────────────────────────────────────────────── --}}
<div class="header">
    <div>
        <div class="header h1">Field Service Report</div>
        <div class="sub">{{ $templateName }}</div>
    </div>
    <div class="ref-badge">{{ $workOrder->reference }}</div>
</div>

{{-- ── Work Order Details ──────────────────────────────────────────────────── --}}
<div class="section">
    <div class="section-title">Work Order Details</div>
    <div class="grid2">
        <div>
            <div class="field-label">Title</div>
            <div class="field-value">{{ $workOrder->title }}</div>
        </div>
        <div>
            <div class="field-label">Type</div>
            <div class="field-value">{{ $workOrder->type->name ?? '—' }}</div>
        </div>
        <div>
            <div class="field-label">Status</div>
            <div class="field-value">
                <span class="status status-{{ $workOrder->status }}">{{ strtoupper(str_replace('_', ' ', $workOrder->status)) }}</span>
            </div>
        </div>
        <div>
            <div class="field-label">Priority</div>
            <div class="field-value">
                <span class="priority priority-{{ $workOrder->priority }}">{{ strtoupper($workOrder->priority) }}</span>
            </div>
        </div>
        <div>
            <div class="field-label">Scheduled Start</div>
            <div class="field-value">{{ $workOrder->scheduled_start ? $workOrder->scheduled_start->format('d M Y, H:i') : '—' }}</div>
        </div>
        <div>
            <div class="field-label">Completed At</div>
            <div class="field-value">{{ $workOrder->completed_at ? $workOrder->completed_at->format('d M Y, H:i') : '—' }}</div>
        </div>
    </div>
</div>

{{-- ── Customer & Site ─────────────────────────────────────────────────────── --}}
<div class="section">
    <div class="section-title">Customer &amp; Site</div>
    <div class="grid2">
        <div>
            <div class="field-label">Customer</div>
            <div class="field-value">{{ $workOrder->customer->name ?? '—' }}</div>
        </div>
        <div>
            <div class="field-label">Phone</div>
            <div class="field-value">{{ $workOrder->customer->phone ?? '—' }}</div>
        </div>
        <div>
            <div class="field-label">Site Name</div>
            <div class="field-value">{{ $workOrder->site_name ?? '—' }}</div>
        </div>
        <div>
            <div class="field-label">Site Address</div>
            <div class="field-value">{{ $workOrder->site_address ?? '—' }}</div>
        </div>
    </div>
</div>

{{-- ── Technicians ─────────────────────────────────────────────────────────── --}}
<div class="section">
    <div class="section-title">Assigned Technician(s)</div>
    <div class="field-value">
        @forelse ($workOrder->technicians as $tech)
            {{ $tech->user->name ?? 'Unknown' }}@if(!$loop->last), @endif
        @empty
            —
        @endforelse
    </div>
</div>

{{-- ── Worksheet Responses ─────────────────────────────────────────────────── --}}
<div class="section">
    <div class="section-title">Worksheet: {{ $templateName }}</div>
    @if(count($rows) > 0)
    <table class="ws-table">
        <thead>
            <tr>
                <th style="width:40%;">Field</th>
                <th>Response</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td>
                    @if ($row['is_photo'] && $row['raw'])
                        @if (str_starts_with($row['raw'], 'data:image'))
                            <img src="{{ $row['raw'] }}" style="max-width:150px; max-height:100px;" />
                        @else
                            {{ $row['value'] }}
                        @endif
                    @else
                        {{ $row['value'] }}
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
        <p style="color:#9ca3af;">No worksheet fields completed.</p>
    @endif
</div>

{{-- ── Signatures ──────────────────────────────────────────────────────────── --}}
<div class="section">
    <div class="section-title">Signatures</div>
    <div style="margin-top:8px;">

        <div class="sig-block">
            @if ($worksheet->technician_signature)
                <img src="{{ $worksheet->technician_signature }}" class="sig-img" />
            @else
                <div style="height:60px; line-height:60px; color:#9ca3af; font-size:11px;">Not signed</div>
            @endif
            <div class="sig-line"></div>
            <div class="sig-label">Technician Signature</div>
            @if ($workOrder->technician_signed_at)
                <div class="sig-timestamp">{{ $workOrder->technician_signed_at->format('d M Y, H:i') }}</div>
            @endif
        </div>

        <div class="sig-block">
            @if ($worksheet->customer_signature)
                <img src="{{ $worksheet->customer_signature }}" class="sig-img" />
            @else
                <div style="height:60px; line-height:60px; color:#9ca3af; font-size:11px;">Not signed</div>
            @endif
            <div class="sig-line"></div>
            <div class="sig-label">Customer Signature</div>
            @if ($workOrder->customer_signed_at)
                <div class="sig-timestamp">{{ $workOrder->customer_signed_at->format('d M Y, H:i') }}</div>
            @endif
        </div>
    </div>
</div>

{{-- ── Footer ──────────────────────────────────────────────────────────────── --}}
<div class="footer">
    Generated on {{ now()->format('d M Y \a\t H:i') }} &nbsp;|&nbsp; {{ $workOrder->reference }}
</div>

</body>
</html>
