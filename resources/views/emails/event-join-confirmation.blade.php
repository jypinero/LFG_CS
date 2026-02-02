<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Join Confirmation</title>
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
            background-color: #1a1a1a;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9f9f9;
            padding: 30px;
            border: 1px solid #ddd;
        }
        .event-details {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .fee-box {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: center;
        }
        .fee-amount {
            font-size: 24px;
            font-weight: bold;
            color: #856404;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 12px;
        }
        .info-row {
            margin: 10px 0;
        }
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 120px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Event Join Confirmation</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ $user->username }},</p>
        
        <p>You have successfully joined the event: <strong>{{ $event->name }}</strong></p>
        
        <div class="event-details">
            <h2>Event Details</h2>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span>{{ \Carbon\Carbon::parse($event->date)->format('F d, Y') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Time:</span>
                <span>
                    @php
                        try {
                            $startTime = \Carbon\Carbon::createFromFormat('H:i:s', $event->start_time)->format('g:i A');
                        } catch (\Exception $e) {
                            $startTime = \Carbon\Carbon::parse($event->start_time)->format('g:i A');
                        }
                        try {
                            $endTime = \Carbon\Carbon::createFromFormat('H:i:s', $event->end_time)->format('g:i A');
                        } catch (\Exception $e) {
                            $endTime = \Carbon\Carbon::parse($event->end_time)->format('g:i A');
                        }
                    @endphp
                    {{ $startTime }} - {{ $endTime }}
                </span>
            </div>
            @if($event->venue)
            <div class="info-row">
                <span class="info-label">Venue:</span>
                <span>{{ $event->venue->name }}</span>
            </div>
            @endif
            @if($event->facility)
            <div class="info-row">
                <span class="info-label">Facility:</span>
                <span>{{ $event->facility->type }}</span>
            </div>
            @endif
            <div class="info-row">
                <span class="info-label">Sport:</span>
                <span>{{ $event->sport }}</span>
            </div>
        </div>
        
        <div class="fee-box">
            <h3>Required Fee</h3>
            <div class="fee-amount">₱{{ number_format($requiredFee, 2) }}</div>
            <p style="margin-top: 10px; color: #856404;">
                Please ensure you have this amount ready for the event.
            </p>
        </div>
        
        <p>We look forward to seeing you at the event!</p>
        
        <p>Best regards,<br>The LFG Team</p>
    </div>
    
    <div class="footer">
        <p>This is an automated email. Please do not reply to this message.</p>
    </div>
</body>
</html>
