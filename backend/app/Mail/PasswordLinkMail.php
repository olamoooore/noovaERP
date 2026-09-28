<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public string $variant,
        public ?string $name = null,
        public ?string $tenantName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->variant === 'invite'
                ? 'You are invited to '.($this->tenantName ?: 'your workspace')
                : 'Reset your NoovaERP password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-link',
        );
    }
}
