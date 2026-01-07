<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;
    public string $email;
    public int $minutes;

    public function __construct(string $token, string $email, int $minutes = 10080)
    {
        $this->token = $token;
        $this->email = $email;
        $this->minutes = $minutes;
    }

    public function build()
    {
        $resetUrl = config('app.frontend_url', 'http://localhost:3000') . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->email);
        
        return $this->subject('Reset Your Password')
            ->view('emails.password-reset')
            ->with([
                'token' => $this->token,
                'email' => $this->email,
                'resetUrl' => $resetUrl,
                'minutes' => $this->minutes,
            ]);
    }
}

























