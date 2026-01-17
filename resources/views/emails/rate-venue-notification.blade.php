<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate the Venue</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 40px;
            margin: 20px 0;
        }
        .email-header {
            text-align: center;
            border-bottom: 3px solid #28a745;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .email-header h1 {
            color: #28a745;
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .email-header p {
            color: #666;
            margin: 5px 0 0 0;
            font-size: 14px;
        }
        .rating-notice {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 20px;
            margin: 30px 0;
            border-radius: 4px;
        }
        .rating-notice p {
            color: #155724;
            margin: 0;
            font-size: 16px;
            font-weight: 500;
        }
        .event-info {
            margin-bottom: 30px;
        }
        .info-section {
            margin-bottom: 25px;
        }
        .info-section h2 {
            color: #333;
            font-size: 18px;
            margin: 0 0 10px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0e0;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
            padding: 8px 0;
        }
        .info-label {
            font-weight: 600;
            color: #555;
            width: 180px;
            flex-shrink: 0;
        }
        .info-value {
            color: #333;
            flex: 1;
        }
        .cta-button {
            display: inline-block;
            background-color: #28a745;
            color: #ffffff;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 16px;
            margin: 30px 0;
            text-align: center;
            transition: background-color 0.3s;
        }
        .cta-button:hover {
            background-color: #218838;
        }
        .cta-container {
            text-align: center;
            margin: 40px 0;
        }
        .message-section {
            background-color: #f8f9fa;
            border-left: 4px solid #6c757d;
            padding: 20px;
            margin: 30px 0;
            border-radius: 4px;
        }
        .message-section p {
            color: #495057;
            margin: 0;
            line-height: 1.8;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            color: #666;
            font-size: 12px;
        }
        .auto-generated {
            color: #999;
            font-size: 11px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>RATE THE VENUE</h1>
            <p>LFG Notification</p>
        </div>

        <div class="rating-notice">
            <p>⭐ Help us improve by rating the venue for your recent game!</p>
        </div>

        <div class="event-info">
            <div class="info-section">
                <h2>Event Details</h2>
                <div class="info-row">
                    <span class="info-label">Event Name:</span>
                    <span class="info-value">{{ $event->name }}</span>
                </div>
                @if($event->description)
                <div class="info-row">
                    <span class="info-label">Description:</span>
                    <span class="info-value">{{ $event->description }}</span>
                </div>
                @endif
                <div class="info-row">
                    <span class="info-label">Sport:</span>
                    <span class="info-value">{{ $event->sport ?? 'N/A' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Event Type:</span>
                    <span class="info-value">{{ ucfirst(str_replace('_', ' ', $event->event_type ?? 'N/A')) }}</span>
                </div>
            </div>

            <div class="info-section">
                <h2>Venue Information</h2>
                <div class="info-row">
                    <span class="info-label">Venue:</span>
                    <span class="info-value">{{ $venue->name }}</span>
                </div>
                @if($venue->address)
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value">{{ $venue->address }}</span>
                </div>
                @endif
            </div>

            <div class="info-section">
                <h2>Schedule</h2>
                <div class="info-row">
                    <span class="info-label">Date:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($event->date)->format('F d, Y') }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Time:</span>
                    <span class="info-value">
                        @if($event->start_time && $event->end_time)
                            {{ \Carbon\Carbon::parse($event->start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($event->end_time)->format('g:i A') }}
                        @else
                            N/A
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <div class="cta-container">
            <a href="{{ $ratingLink }}" class="cta-button">Rate This Venue</a>
        </div>

        <div class="message-section">
            <p>Your feedback helps us maintain quality venues and improve the experience for all players. Please take a moment to rate the venue based on your experience.</p>
        </div>

        <div class="footer">
            <p>This is an automated notification from LFG (Looking For Game)</p>
            <p class="auto-generated">Auto-generated email - Please do not reply to this email</p>
        </div>
    </div>
</body>
</html>
