<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
	use Queueable, SerializesModels;

	public string $code;
	public int $minutes;

	public function __construct(string $code, int $minutes = 10)
	{
		$this->code = $code;
		$this->minutes = $minutes;
	}

	public function build()
	{
		return $this->subject('Your One-Time Password')
			->view('emails.otp')
			->with([
				'code' => $this->code,
				'minutes' => $this->minutes,
			]);
	}
}


