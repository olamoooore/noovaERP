<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduled Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #3b82f6;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9fafb;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        .report-info {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #3b82f6;
        }
        .report-info h3 {
            margin-top: 0;
            color: #1f2937;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #6b7280;
        }
        .value {
            color: #1f2937;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Scheduled Report</h1>
    </div>
    
    <div class="content">
        <p>Hello,</p>
        
        <p>Your scheduled report has been generated and is attached to this email.</p>
        
        <div class="report-info">
            <h3>Report Details</h3>
            
            <div class="info-row">
                <span class="label">Report Name:</span>
                <span class="value">{{ $report_name }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">Report Type:</span>
                <span class="value">{{ ucwords(str_replace('_', ' ', $report_type)) }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">Frequency:</span>
                <span class="value">{{ ucfirst($frequency) }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">Generated At:</span>
                <span class="value">{{ now()->format('F j, Y g:i A') }}</span>
            </div>
        </div>
        
        <p>The report is attached to this email. Please review it at your convenience.</p>
        
        <p>If you have any questions or need assistance, please contact your system administrator.</p>
    </div>
    
    <div class="footer">
        <p>This is an automated email from your ERP system.</p>
        <p>© {{ date('Y') }} NoovaERP. All rights reserved.</p>
    </div>
</body>
</html>
