<?php

namespace App\Console\Commands;

use App\Services\InboundEmailService;
use Illuminate\Console\Command;

/**
 * Issue #85 — skeleton artisan command for IMAP polling.
 *
 * The actual IMAP connect/listen logic is intentionally not bundled
 * here: the codebase does not depend on an IMAP library yet, and the
 * ingestion contract is owned by \`InboundEmailService\` so the
 * command (or any other producer) only needs to construct the same
 * payload shape.
 *
 * Operators wire a real IMAP poller by either:
 *
 *   1. Posting to the webhook endpoint from a cron-driven shell
 *      script (SendGrid Inbound Parse, Postmark, etc.).
 *   2. Replacing this command body with an IMAP library of choice.
 *
 * Until (2) lands, this command is the place to wire your library;
 * the \`--dry-run\` flag prints the resolved config without
 * connecting.
 */
class PollInboundEmailsCommand extends Command
{
    protected $signature = 'inbound-email:poll {--dry-run : Print config without connecting}';
    protected $description = 'Poll configured IMAP mailbox(es) and ingest replies via InboundEmailService.';

    public function handle(InboundEmailService $service): int
    {
        $config = [
            'host' => env('INBOUND_EMAIL_HOST'),
            'port' => (int) env('INBOUND_EMAIL_PORT', 993),
            'username' => env('INBOUND_EMAIL_USERNAME'),
            'mailbox' => env('INBOUND_EMAIL_MAILBOX', 'INBOX'),
            'token' => env('INBOUND_EMAIL_TOKEN'),
        ];

        $missing = array_filter(
            ['host' => $config['host'], 'username' => $config['username'], 'token' => $config['token']],
            fn ($v) => ! is_string($v) || $v === '',
        );

        if ($missing !== []) {
            $this->warn('Inbound email bridge is partially configured. Missing: '.implode(', ', array_keys($missing)));
            $this->line('Set INBOUND_EMAIL_HOST, INBOUND_EMAIL_USERNAME, INBOUND_EMAIL_TOKEN in your .env to enable.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — would connect to '.$config['host'].':'.$config['port'].' as '.$config['username'].' and ingest mail from '.$config['mailbox']);
            return self::SUCCESS;
        }

        $this->info('Inbound email bridge is live via the webhook endpoint — see App\Services\InboundEmailService for the ingestion contract. Wire your IMAP poller or hosted-mail-provider inbound webhook to POST /api/v1/webhooks/inbound-email with the parsed payload.');
        return self::SUCCESS;
    }
}
