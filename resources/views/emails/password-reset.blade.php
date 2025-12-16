<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px;">
        <h2 style="color: #333; margin-top: 0;">Password Reset Request</h2>
        
        <p>Hello,</p>
        
        <p>You are receiving this email because we received a password reset request for your account.</p>
        
        <p>Click the button below to reset your password:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $resetUrl }}" style="background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;">Reset Password</a>
        </div>
        
        <p>Or copy and paste this URL into your browser:</p>
        <p style="word-break: break-all; background-color: #f9f9f9; padding: 10px; border-radius: 3px; font-size: 12px;">{{ $resetUrl }}</p>
        
        <p>This password reset link will expire in {{ $minutes }} minutes ({{ round($minutes / 60 / 24, 1) }} days).</p>
        
        <p>If you did not request a password reset, no further action is required.</p>
        
        <p style="margin-top: 30px; font-size: 12px; color: #666;">
            Regards,<br>
            {{ config('app.name', 'LFG Team') }}
        </p>
    </div>
</body>
</html>








