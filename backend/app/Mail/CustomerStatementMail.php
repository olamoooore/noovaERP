<?php

namespace App\Mail;

use App\Models\CustomerStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CustomerStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CustomerStatement $statement)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Account Statement'
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer-statement',
            with: [
                'statement' => $this->statement,
                'payload' => $this->statement->payload,
            ]
        );
    }

    public function attachments(): array
    {
        if (!$this->statement->pdf_path || !Storage::disk('public')->exists($this->statement->pdf_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('public', $this->statement->pdf_path)
                ->as('customer-statement-' . $this->statement->id . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
