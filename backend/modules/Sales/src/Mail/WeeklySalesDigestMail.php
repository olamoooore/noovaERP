<?php

namespace Modules\Sales\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Issue #606 — the email leg of the weekly sales digest. The body is already
 * rendered plain text (built from the shared read models), so the mailable
 * only wraps it for delivery.
 */
class WeeklySalesDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $digestSubject,
        public string $digestBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->digestSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-sales-digest',
            with: ['body' => $this->digestBody],
        );
    }
}
