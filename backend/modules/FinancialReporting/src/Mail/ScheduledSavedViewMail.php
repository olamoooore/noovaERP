<?php

namespace Modules\FinancialReporting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Issue #598 (RPT-UX3) — the email leg of a scheduled saved report view.
 * The CSV/PDF is generated server-side and attached; the body reuses the
 * module's existing scheduled-report template.
 */
class ScheduledSavedViewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $viewName,
        public string $frequency,
        public string $format,
        public string $filePath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Scheduled report: {$this->viewName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'financial-reporting::emails.scheduled-report',
            with: [
                'report_name' => $this->viewName,
                'report_type' => 'saved_view',
                'frequency' => $this->frequency,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->filePath),
        ];
    }
}
