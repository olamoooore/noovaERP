<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f0f1a; margin: 0; padding: 0; color: #ffffff; }
        .wrapper { max-width: 600px; margin: 0 auto; background: #0f0f1a; }
        .header { background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); padding: 40px 32px; text-align: center; border-radius: 0 0 24px 24px; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #ffffff; letter-spacing: -0.5px; }
        .header p { margin: 8px 0 0; font-size: 15px; color: rgba(255,255,255,0.85); }
        .content { padding: 32px; }
        .greeting { font-size: 17px; color: #e2e8f0; margin-bottom: 24px; }
        .ticket-card { background: #1e1b2e; border: 1px solid rgba(139,92,246,0.3); border-radius: 16px; padding: 28px; margin-bottom: 24px; }
        .ticket-card h2 { margin: 0 0 20px; font-size: 22px; color: #a78bfa; font-weight: 700; }
        .queue-badge { display: inline-block; background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); color: #fff; font-size: 36px; font-weight: 800; padding: 12px 28px; border-radius: 12px; margin: 12px 0 20px; letter-spacing: 4px; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.07); font-size: 14px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #94a3b8; }
        .detail-value { color: #e2e8f0; font-weight: 500; text-align: right; }
        .reference-box { background: rgba(139,92,246,0.1); border: 1px solid rgba(139,92,246,0.2); border-radius: 10px; padding: 16px; margin-top: 20px; text-align: center; }
        .reference-box .label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #a78bfa; margin-bottom: 4px; }
        .reference-box .ref { font-size: 18px; font-weight: 700; color: #ffffff; font-family: monospace; letter-spacing: 2px; }
        .instructions { background: #1e1b2e; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .instructions h3 { margin: 0 0 12px; font-size: 15px; color: #a78bfa; }
        .instructions ul { margin: 0; padding-left: 18px; }
        .instructions li { color: #94a3b8; font-size: 13px; line-height: 1.8; }
        .footer { text-align: center; padding: 24px 32px; color: #475569; font-size: 12px; }
        .footer a { color: #8b5cf6; text-decoration: none; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>🎟 You're IN!</h1>
        <p>Your booking is confirmed for <strong>{{ $eventTitle }}</strong></p>
    </div>

    <div class="content">
        <p class="greeting">Hi <strong>{{ $buyerName }}</strong>,</p>
        <p style="color:#94a3b8;font-size:15px;margin-bottom:24px;">
            We're thrilled to have you at <strong style="color:#e2e8f0">{{ $eventTitle }}</strong>.
            Your spot is secured — here are your booking details below.
        </p>

        <!-- Ticket Card -->
        <div class="ticket-card">
            <h2>{{ $eventTitle }}</h2>

            <div style="text-align:center;margin-bottom:20px;">
                <p style="margin:0;color:#94a3b8;font-size:12px;text-transform:uppercase;letter-spacing:1.5px;">Your Queue Number</p>
                <div class="queue-badge">#{{ $queueNumber }}</div>
            </div>

            @if($eventDate)
            <div class="detail-row">
                <span class="detail-label">📅 Date</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($eventDate)->format('D, d M Y, g:i A') }}</span>
            </div>
            @endif

            @if($venue)
            <div class="detail-row">
                <span class="detail-label">📍 Venue</span>
                <span class="detail-value">{{ $venue }}</span>
            </div>
            @endif

            <div class="detail-row">
                <span class="detail-label">👤 Name</span>
                <span class="detail-value">{{ $buyerName }}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">📧 Email</span>
                <span class="detail-value">{{ $buyerEmail }}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">💳 Amount</span>
                <span class="detail-value">
                    @if($totalAmount == 0) FREE @else {{ $currency }} {{ number_format($totalAmount, 2) }} @endif
                </span>
            </div>

            <div class="reference-box">
                <div class="label">Booking Reference</div>
                <div class="ref">{{ $bookingReference }}</div>
            </div>
        </div>

        <!-- Instructions -->
        <div class="instructions">
            <h3>📋 What to bring on the day</h3>
            <ul>
                <li>This confirmation email (printed or on your phone)</li>
                <li>Your queue number <strong>#{{ $queueNumber }}</strong></li>
                <li>A valid photo ID</li>
                <li>Arrive at least 30 minutes before the event starts</li>
            </ul>
        </div>
    </div>

    <div class="footer">
        <p>Questions? Reply to this email or contact us at <a href="mailto:support@noovaerp.com">support@noovaerp.com</a></p>
        <p style="margin-top:8px;">© {{ date('Y') }} NoovaERP · Singapore</p>
    </div>
</div>
</body>
</html>
