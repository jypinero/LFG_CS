<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;

class RateVenueNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Event $event;
    public User $user;
    public Venue $venue;
    public string $ratingLink;

    public function __construct(Event $event, User $user, Venue $venue)
    {
        $this->event = $event;
        $this->user = $user;
        $this->venue = $venue;
        $this->ratingLink = 'https://www.lfg-ph.games/rate-venue?event_id=' . $event->id . '&venue_id=' . $venue->id;
    }

    public function build()
    {
        return $this->subject('Rate the venue for your recent game: ' . $this->event->name)
            ->view('emails.rate-venue-notification')
            ->with([
                'event' => $this->event,
                'user' => $this->user,
                'venue' => $this->venue,
                'ratingLink' => $this->ratingLink,
            ]);
    }
}
