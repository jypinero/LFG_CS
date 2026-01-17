<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Event;
use App\Models\User;

class EventCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public Event $event;
    public User $user;

    public function __construct(Event $event, User $user)
    {
        $this->event = $event;
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('Event Cancelled - ' . $this->event->name)
            ->view('emails.event-cancelled')
            ->with([
                'event' => $this->event,
                'user' => $this->user,
            ]);
    }
}
