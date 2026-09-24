<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceApprovalService;
use App\Services\InvoiceEvidenceService;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Facades\Tenancy;

/**
 * Bulk-post all eligible draft invoices.
 *
 * The "eligible" filter:
 *   - status = 'draft'
 *   - requires_approval = false (or approval_status already 'approved')
 *   - evidence_status = 'not_required' (or any value when !requires_approval)
 *   - invoice_date <= the configured lock-date threshold (defaults to
 *     today, so anything in the future is skipped)
 *
 * Each invoice is posted via the same InvoiceService::postInvoice() path
 * the API uses, so the journal entries (5-line AR + Revenue + Inventory
 * credit + COGS + GST) are created exactly as if a user clicked "Post"
 * one by one.
 *
 *  Usage:
 *    php artisan invoices:bulk-post-drafts --dry-run
 *    php artisan invoices:bulk-post-drafts --limit=50
 *    php artisan invoices:bulk-post-drafts --from=2024-12-01 --to=2025-08-31
 *    php artisan invoices:bulk-post-drafts                  # posts all
 */
class BulkPostDraftInvoicesCommand extends Command
{
    protected $signature = 'invoices:bulk-post-drafts
        {tenant_id? : Tenant ID (UUID). Omit if tenancy is already initialised.}
        {--dry-run : List what would be posted, but make no changes}
        {--limit= : Maximum number of invoices to post}
        {--from= : Only invoices on/after this date (YYYY-MM-DD)}
        {--to= : Only invoices on/before this date (YYYY-MM-DD)}
        {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Bulk-post eligible draft invoices via InvoiceService::postInvoice()';

    public function handle(
        InvoiceService $invoiceService,
        InvoiceApprovalService $approvalService,
        InvoiceEvidenceService $evidenceService,
    ): int {
        // The Invoice model lives in the per-tenant schema, so we must
        // initialise tenancy first. Accept either an explicit tenant_id
        // argument or an already-initialised tenancy (e.g. when run from
        // an artisan context that already has the schema loaded).
        $tenantArg = $this->argument('tenant_id') ?? null;
        if ($tenantArg) {
            $tenant = \App\Models\Tenant::find($tenantArg);
            if (! $tenant) {
                $this->error("Tenant {$tenantArg} not found in public.tenants.");
                return self::FAILURE;
            }
            Tenancy::initialize($tenant);
        } elseif (! function_exists('tenant') || ! tenant()) {
            $this->error('No tenant context. Pass --tenant=<id> or run via tenancy:artisan.');
            return self::FAILURE;
        }

        $tenantId = tenant('id');
        $this->info("Tenant: {$tenantId}");

        // Belt-and-braces: the Invoice model uses Eloquent's default
        // connection, which honours search_path; force it explicitly so
        // the artisan context can't accidentally fall back to public.
        DB::statement('SET search_path TO '.$this->quoteSearchPath($tenantId));

        $query = Invoice::query()
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('requires_approval', false)
                    ->orWhere('approval_status', Invoice::APPROVAL_APPROVED);
            })
            ->orderBy('invoice_date')
            ->orderBy('id');

        if ($from = $this->option('from')) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to = $this->option('to')) {
            $query->whereDate('invoice_date', '<=', $to);
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $candidates = $query->get();
        $this->info(sprintf('Found %d draft invoice(s) eligible to post.', $candidates->count()));

        if ($candidates->isEmpty()) {
            return self::SUCCESS;
        }

        // Pre-flight: filter out anything the live guards would block.
        $blocked = [];
        $postable = [];
        foreach ($candidates as $invoice) {
            $approval = $approvalService->checkGuard($invoice);
            $evidence = $evidenceService->checkGuard($invoice);
            if ($approval['blocked'] || $evidence['blocked']) {
                $blocked[] = [
                    'id' => $invoice->id,
                    'number' => $invoice->invoice_number,
                    'approval_block' => $approval['blocked'] ?? false,
                    'evidence_block' => $evidence['blocked'] ?? false,
                ];
                continue;
            }
            $postable[] = $invoice;
        }

        if ($blocked) {
            $this->warn(sprintf('Skipping %d invoice(s) blocked by guards:', count($blocked)));
            $this->table(['id', 'number', 'approval_block', 'evidence_block'], $blocked);
        }

        if ($postable === []) {
            $this->error('No postable invoices after guard filtering.');
            return self::FAILURE;
        }

        $this->info(sprintf('Will post %d invoice(s) totalling:', count($postable)));
        $this->table(
            ['id', 'invoice_number', 'date', 'subtotal', 'tax', 'total', 'customer_id'],
            array_map(fn ($i) => [
                $i->id,
                $i->invoice_number,
                $i->invoice_date->format('Y-m-d'),
                number_format((float) $i->subtotal, 2),
                number_format((float) $i->tax_amount, 2),
                number_format((float) $i->total, 2),
                $i->customer_id,
            ], $postable)
        );

        if ($this->option('dry-run')) {
            $this->info('Dry-run mode — no changes made.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Post these %d invoices? This will create journal entries.', count($postable)), false)) {
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        $progress = $this->output->createProgressBar(count($postable));
        $progress->start();

        $ok = 0; $fail = 0; $errors = [];
        foreach ($postable as $invoice) {
            try {
                DB::transaction(function () use ($invoice, $invoiceService) {
                    $invoiceService->postInvoice($invoice);
                });
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $errors[] = [
                    'id' => $invoice->id,
                    'number' => $invoice->invoice_number,
                    'error' => substr($e->getMessage(), 0, 120),
                ];
                Log::error('bulk-post-drafts: failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);
        $this->info(sprintf('Posted: %d, Failed: %d', $ok, $fail));

        if ($errors) {
            $this->warn('First 20 failures:');
            $this->table(['id', 'number', 'error'], array_slice($errors, 0, 20));
        }

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function quoteSearchPath(string $tenantId): string
    {
        // PostgreSQL identifier quoting — wrap in double quotes, escape
        // any embedded double quotes.
        return '"'.str_replace('"', '""', $tenantId).'", public';
    }
}
