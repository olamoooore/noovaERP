<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ACC-RM-001 regression guard (ADR-005 §12):
 *
 * "Operational domains may not submit unrestricted debit/credit lines."
 *
 * Every service under backend/app/Services and backend/app/Accounting/Services
 * must post through the controlled domain contract
 * (PostingEngine::domainPosting + DomainPostingRequest, profile-resolved by
 * PostingPolicyMapper) — not by hand-building PostingRequestLine arrays.
 *
 * The engine's own canonical files and an explicit, DATED allowlist of
 * legacy call sites are the only exemptions. The allowlist must only ever
 * SHRINK: adding a file requires a tracked ACC-RM work item, and removing
 * its last usage must delete the entry (stale entries fail).
 *
 * This test is demanded by DAILY_REPORT_2026-09-01 §H risk 4.
 */
class PostingLineConstructionGuardTest extends TestCase
{
    /**
     * Legacy call sites still constructing unrestricted PostingRequestLine,
     * as enumerated on 2026-09-02 (the day the guard went live — it found
     * 12 callers vs. the 7 the 2026-09-01 report listed).
     *
     * Each entry is tracked ACC-RM-001 migration debt. The list may only
     * ever SHRINK; deleting a file's last raw-line construction without
     * removing its entry fails this test (stale-entry check below), and any
     * NEW file constructing raw lines fails the primary assertion.
     */
    private const LEGACY_ALLOWLIST = [
        // InvoiceService's AR invoice POSTING migrated to domainPosting on
        // 2026-09-02; only the payment-receipt block remains legacy, pending
        // the explicit-bank-account line contract decision (profiles cannot
        // pin a per-payment GL account yet).
        'InvoiceService.php',
        'BillPaymentService.php',
        'BankReconciliationService.php',
        'PaymentRefundService.php',
        'PaymentPostingService.php',
        'DepreciationService.php',
        'FxRevaluationService.php',
        'IntercompanyPostingService.php',
        'AccountingOriginBillService.php',
        'VendorBillService.php',
        'VendorCreditNoteService.php',
        'VendorDebitNoteService.php',
        'CustomerCreditNoteService.php',
    ];

    /** Files that OWN the raw-line plumbing and must construct lines. */
    private const ENGINE_INTERNAL = [
        'PostingEngine.php',
        'PostingPolicyMapper.php',
    ];

    public function test_operational_services_do_not_construct_unrestricted_posting_lines(): void
    {
        $dirs = [
            base_path('app/Services'),
            base_path('app/Accounting/Services'),
        ];

        $offenders = [];
        $seenAllowlist = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $files = iterator_to_array(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            ), false);

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $name = $file->getFilename();
                if (in_array($name, self::ENGINE_INTERNAL, true)) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());

                $constructsLines = str_contains($content, 'new PostingRequestLine(');
                $submitsRaw = str_contains($content, '->post(new PostingRequest(');

                if (! $constructsLines && ! $submitsRaw) {
                    continue;
                }

                if (in_array($name, self::LEGACY_ALLOWLIST, true)) {
                    $seenAllowlist[] = $name;

                    continue;
                }

                $offenders[] = basename($dir).'/'.$name;
            }
        }

        // 1. No new unrestricted-line caller outside the allowlist.
        $this->assertSame(
            [],
            $offenders,
            'ACC-RM-001 violated — these services build unrestricted posting lines instead of DomainPostingRequest: '
            .implode(', ', $offenders)
        );

        // 2. Stale allowlist entries fail (forces the list to shrink).
        $stale = array_values(array_diff(self::LEGACY_ALLOWLIST, $seenAllowlist));
        $this->assertSame(
            [],
            $stale,
            'PostingLineConstructionGuardTest allowlist entries no longer construct raw lines and must be removed: '
            .implode(', ', $stale)
        );
    }

    public function test_invoice_posting_path_no_longer_constructs_raw_lines(): void
    {
        // The migrated invoice POSTING block must use the domain contract.
        $content = file_get_contents(base_path('app/Services/InvoiceService.php'));

        $this->assertStringContainsString('domainPosting(new DomainPostingRequest(', $content);
        $this->assertStringContainsString("postingProfileCode: 'AR_INVOICE_POST'", $content);
        // Exactly one legacy ->post(new PostingRequest( remains: the
        // payment-receipt block (pending explicit-bank-account contract).
        $this->assertSame(
            1,
            substr_count($content, '->post(new PostingRequest('),
            'InvoiceService must not grow new raw PostingRequest call sites'
        );
    }
}
