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
        public ?string $qrDataUri = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        $html = nl2br(e($this->emailBody));

        // D8: inline QR for the pay-now link so phone users can scan from the
        // email itself (raster PNG when Imagick exists, SVG otherwise).
        if ($this->qrDataUri) {
            $html .= '<br><img src="'.e($this->qrDataUri).'" alt="Payment QR code" width="180" height="180">';
        }

        return new Content(htmlString: $html);
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $a['data'],
                $a['name'],
            )->withMime($a['mime'] ?? 'application/octet-stream'),
            $this->emailAttachments,
        );
    }
}
