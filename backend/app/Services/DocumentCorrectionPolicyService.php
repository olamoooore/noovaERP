<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class DocumentCorrectionPolicyService
{
    public function canVoid(Invoice|Bill|JournalEntry $document, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if (! in_array($document->lifecycle_status ?? 'draft', ['posted'])) {
            return PolicyResult::deny('Document must be posted to void');
        }

        if ($document instanceof Invoice || $document instanceof Bill) {
            if ($document->settlement_status === 'paid') {
                return PolicyResult::deny('Cannot void document with payments. Reverse or refund payment first.');
            }

            if ($document->settlement_status === 'partially_paid') {
                return PolicyResult::deny('Cannot void document with partial payments. Reverse or refund payments first.');
            }

            if ($document->reconciliation_status && $document->reconciliation_status !== 'unreconciled') {
                return PolicyResult::deny('Cannot void reconciled document. Unreconcile first.');
            }
        }

        if ($document instanceof JournalEntry && $document->lines()->whereNotNull('reconciliation_id')->exists()) {
            return PolicyResult::deny('Cannot void reconciled journal entry');
        }

        $hasPermission = $this->checkPermission($document, 'void', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to void this document');
        }

        return PolicyResult::allow();
    }

    public function canReverse(Invoice|Bill|JournalEntry $document, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if (! in_array($document->lifecycle_status ?? 'draft', ['posted'])) {
            return PolicyResult::deny('Document must be posted to reverse');
        }

        if ($document instanceof Invoice || $document instanceof Bill) {
            if ($document->settlement_status === 'paid' || $document->settlement_status === 'partially_paid') {
                return PolicyResult::allow([
                    'warning' => 'Document has payments. Payment reversal may be required.',
                ]);
            }
        }

        $hasPermission = $this->checkPermission($document, 'reverse', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to reverse this document');
        }

        return PolicyResult::allow();
    }

    public function canCreateReplacement(Invoice|Bill|JournalEntry $document, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if (! in_array($document->lifecycle_status ?? 'draft', ['posted'])) {
            return PolicyResult::deny('Document must be posted to create replacement');
        }

        if ($document->lifecycle_status === 'superseded') {
            return PolicyResult::deny('Document is already superseded');
        }

        if ($document->original_document_id) {
            return PolicyResult::deny('This document is already a replacement. Edit the replacement instead.');
        }

        if ($document instanceof Invoice || $document instanceof Bill) {
            if ($document->settlement_status === 'paid') {
                return PolicyResult::deny('Document is fully paid. Use credit note or refund workflow instead.', [
                    'suggested_actions' => ['credit_note', 'reverse_and_refund'],
                ]);
            }

            if ($document->settlement_status === 'partially_paid') {
                return PolicyResult::deny('Document has partial payments. Resolve payment issue first.', [
                    'suggested_actions' => ['reverse_and_refund'],
                ]);
            }

            if ($document->reconciliation_status && $document->reconciliation_status !== 'unreconciled') {
                return PolicyResult::deny('Document is reconciled. Unreconcile first.');
            }
        }

        $hasPermission = $this->checkPermission($document, 'replace', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to replace this document');
        }

        return PolicyResult::allow();
    }

    public function canRefund(Invoice|Bill $document, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if ($document->settlement_status !== 'paid') {
            return PolicyResult::deny('Document must be paid to refund');
        }

        $hasPermission = $this->checkPermission($document, 'refund', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to refund this document');
        }

        return PolicyResult::allow();
    }

    public function canCreateCreditNote(Invoice|Bill $document, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if (! in_array($document->lifecycle_status ?? 'draft', ['posted'])) {
            return PolicyResult::deny('Document must be posted to create credit note');
        }

        $hasPermission = $this->checkPermission($document, 'credit_note', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to create credit note');
        }

        return PolicyResult::allow();
    }

    public function canUnapplyPayment(Payment $payment, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if ($payment->payment_status !== 'applied') {
            return PolicyResult::deny('Payment must be applied to unapply');
        }

        $hasPermission = $this->checkPermission($payment, 'unapply', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to unapply payment');
        }

        return PolicyResult::allow();
    }

    public function canReversePayment(Payment $payment, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if (! in_array($payment->payment_status, ['applied', 'recorded'])) {
            return PolicyResult::deny('Payment cannot be reversed in current status');
        }

        $hasPermission = $this->checkPermission($payment, 'reverse', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to reverse payment');
        }

        return PolicyResult::allow();
    }

    public function canRefundPayment(Payment $payment, ?User $user = null): PolicyResult
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return PolicyResult::deny('No user authenticated');
        }

        if ($payment->payment_status !== 'applied') {
            return PolicyResult::deny('Payment must be applied to refund');
        }

        $hasPermission = $this->checkPermission($payment, 'refund', $user);
        if (! $hasPermission) {
            return PolicyResult::deny('User lacks permission to refund payment');
        }

        return PolicyResult::allow();
    }

    private function checkPermission(Invoice|Bill|JournalEntry|Payment $document, string $action, User $user): bool
    {
        $permission = match (true) {
            $document instanceof Invoice => "accounting.invoice.{$action}",
            $document instanceof Bill => "accounting.bill.{$action}",
            $document instanceof JournalEntry => "accounting.journal.{$action}",
            $document instanceof Payment => "accounting.payment.{$action}",
            default => null,
        };

        if (! $permission) {
            return false;
        }

        // Coarse fallback chain. RolePermissionSeeder::PERMISSION_MATRIX
        // seeds accounting.view/write/post/reverse/lock/settings — the
        // fine-grained accounting.{type}.{action} strings were never
        // seeded into any tenant, so every void/reverse/replace/refund
        // attempt failed the gate for every user (confirmed in production
        // 2026-09-04 while clearing Acme test JEs).
        $coarse = match ($action) {
            'void', 'reverse', 'refund', 'credit_note' => 'accounting.reverse',
            default => 'accounting.write',
        };

        // Platform bridge first: central tenant role (user_company_access)
        // -> spatie tenant role permissions, with OWNER/ADMIN super-role
        // bypass — this is how the rest of the stack authorizes actions.
        try {
            $tenantId = (string) (tenant()?->id ?? '');
            if ($tenantId !== '') {
                if (app(TenantRoleService::class)->hasAnyPermission((int) $user->id, $tenantId, [$permission, $coarse])) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // fall through to direct checks
        }

        return $user->can($permission) || $user->can($coarse);
    }
}

class PolicyResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $message = null,
        public readonly array $data = []
    ) {}

    public static function allow(array $data = []): self
    {
        return new self(true, null, $data);
    }

    public static function deny(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    public function message(): string
    {
        return $this->message ?? ($this->allowed ? 'Allowed' : 'Denied');
    }

    public function warning(): ?string
    {
        return $this->data['warning'] ?? null;
    }

    public function suggestedActions(): array
    {
        return $this->data['suggested_actions'] ?? [];
    }
}
