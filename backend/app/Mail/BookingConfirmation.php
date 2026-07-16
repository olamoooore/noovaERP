<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public string $bookingReference;

    public string $queueNumber;

    public string $eventTitle;

    public string $buyerName;

    public string $buyerEmail;

    public float $totalAmount;

    public string $currency;

    public ?string $eventDate;

    public ?string $venue;

    public function __construct(array $data)
    {
        $this->bookingReference = $data['booking_reference'];
        $this->queueNumber = $data['queue_number'];
        $this->eventTitle = $data['event_title'];
        $this->buyerName = $data['buyer_name'];
        $this->buyerEmail = $data['buyer_email'];
        $this->totalAmount = (float) ($data['total_amount'] ?? 0);
        $this->currency = $data['currency'] ?? 'SGD';
        $this->eventDate = $data['event_date'] ?? null;
        $this->venue = $data['venue'] ?? null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Confirmed – '.$this->eventTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmation',
        );
    }
}
