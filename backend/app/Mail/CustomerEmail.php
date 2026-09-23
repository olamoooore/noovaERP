<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{name: string, data: string, mime: string}>  $emailAttachments
     */
    public function __construct(
        public string $emailSubject,
        public string $emailBody,
        public array $emailAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->emailBody)),
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => new \Illuminate\Mail\Mailables\Attachment(
                data: $a['data'],
                name: $a['name'],
                mime: $a['mime'] ?? 'application/octet-stream',
            ),
            $this->emailAttachments,
        );
    }
}
