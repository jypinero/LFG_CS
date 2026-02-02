<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Event;
use App\Models\User;

class EventJoinConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Event $event;
    public User $user;
    public float $requiredFee;

    public function __construct(Event $event, User $user, float $requiredFee)
    {
        $this->event = $event;
        $this->user = $user;
        $this->requiredFee = $requiredFee;
    }

    public function build()
    {
        return $this->subject('Event Join Confirmation - ' . $this->event->name)
            ->view('emails.event-join-confirmation')
            ->with([
                'event' => $this->event,
                'user' => $this->user,
                'requiredFee' => $this->requiredFee,
            ]);
    }
}
