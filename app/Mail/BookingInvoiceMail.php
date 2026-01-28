<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Booking;

class BookingInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public float $totalCost;
    public float $costPerSlotIfFull;
    public float $costPerSlotCurrent;
    public int $participantsCount;
    public int $slots;

    public function __construct(
        Booking $booking,
        float $totalCost,
        float $costPerSlotIfFull,
        float $costPerSlotCurrent,
        int $participantsCount,
        int $slots
    ) {
        $this->booking = $booking;
        $this->totalCost = $totalCost;
        $this->costPerSlotIfFull = $costPerSlotIfFull;
        $this->costPerSlotCurrent = $costPerSlotCurrent;
        $this->participantsCount = $participantsCount;
        $this->slots = $slots;
    }

    public function build()
    {
        return $this->subject('Required Fee - ' . $this->booking->venue->name)
            ->view('emails.booking-invoice')
            ->with([
                'booking' => $this->booking,
                'totalCost' => $this->totalCost,
                'costPerSlotIfFull' => $this->costPerSlotIfFull,
                'costPerSlotCurrent' => $this->costPerSlotCurrent,
                'participantsCount' => $this->participantsCount,
                'slots' => $this->slots,
            ]);
    }
}
