<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the canonical posting profile catalog per ADR-005 §12.
 *
 * Profiles are seeded per-tenant with concrete account_ids resolved from the
 * tenant's chart_of_accounts and company_settings. The mapper resolves
 * these at runtime; here we record the role-to-account mapping for each
 * supported (source_capability, posting_profile_code) pair.
 *
 * Account roles use the canonical keys from AccountRoleResolver::KEY_TO_ROLE
 * so that role-based resolution works out of the box for any tenant with
 * entity_account_roles populated.
 */
class PostingProfileSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::selectOne('SELECT id FROM public.tenants LIMIT 1')?->id;
        if (! $tenantId) {
            return;
        }

        $now = now();

        $profiles = [
            // ACC-003 AR — invoice posting (DR Receivable, CR Revenue + Tax)
            [
                'source_capability' => 'ACC-003',
                'posting_profile_code' => 'AR_INVOICE_POST',
                'name' => 'Accounts Receivable — Invoice Posting',
                'description' => 'Standard AR invoice: debit Receivable, credit Revenue + Tax Collected',
                'mappings' => json_encode([
                    'accounts_receivable' => ['direction' => 'debit'],
                    'sales_revenue' => ['direction' => 'credit'],
                    'shipping_revenue' => ['direction' => 'credit'],
                    'sales_tax_payable' => ['direction' => 'credit'],
                    'cogs' => ['direction' => 'debit'],
                    'inventory' => ['direction' => 'credit'],
                    // Issue #880 (SA7) / MRD FR-7.4: FX rounding residual
                    // lands on the existing FX gain/loss role accounts.
                    'realized_fx_gain' => ['direction' => 'credit'],
                    'realized_fx_loss' => ['direction' => 'debit'],
                ]),
                'control_account_requirements' => json_encode([
                    ['account_role' => 'accounts_receivable', 'source_capability' => 'ACC-003', 'requires_exceptional_authority' => false],
                ]),
            ],
            // ACC-003 AR — payment receipt (DR Cash/Bank, CR Receivable)
            [
                'source_capability' => 'ACC-003',
                'posting_profile_code' => 'AR_PAYMENT_RECEIPT',
                'name' => 'Accounts Receivable — Payment Receipt',
                'description' => 'Customer payment received: debit Bank/Cash, credit Receivable',
                'mappings' => json_encode([
                    'bank' => ['direction' => 'debit'],
                    'accounts_receivable' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-004 AP — bill posting (DR Expense + Tax Input, CR Payable)
            [
                'source_capability' => 'ACC-004',
                'posting_profile_code' => 'AP_BILL_POST',
                'name' => 'Accounts Payable — Bill Posting',
                'description' => 'Standard AP bill: debit Expense + Tax Input, credit Payable',
                'mappings' => json_encode([
                    'general_expense' => ['direction' => 'debit'],
                    'input_tax' => ['direction' => 'debit'],
                    'accounts_payable' => ['direction' => 'credit'],
                ]),
                'control_account_requirements' => json_encode([
                    ['account_role' => 'accounts_payable', 'source_capability' => 'ACC-004', 'requires_exceptional_authority' => false],
                ]),
            ],
            // ACC-004 AP — vendor payment (DR Payable, CR Bank)
            [
                'source_capability' => 'ACC-004',
                'posting_profile_code' => 'AP_VENDOR_PAYMENT',
                'name' => 'Accounts Payable — Vendor Payment',
                'description' => 'Vendor payment: debit Payable, credit Bank',
                'mappings' => json_encode([
                    'accounts_payable' => ['direction' => 'debit'],
                    'bank' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-005A Payment Execution (DR Payable/Receivable, CR Bank)
            [
                'source_capability' => 'ACC-005A',
                'posting_profile_code' => 'PAYMENT_EXECUTION',
                'name' => 'Payments — Execution',
                'description' => 'Payment execution: debit Payable/Receivable, credit Bank',
                'mappings' => json_encode([
                    'accounts_payable' => ['direction' => 'debit'],
                    'accounts_receivable' => ['direction' => 'debit'],
                    'bank' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-006 Cash & Banking — receipt
            [
                'source_capability' => 'ACC-006',
                'posting_profile_code' => 'BANK_RECEIPT',
                'name' => 'Cash & Banking — Receipt',
                'description' => 'Cash/bank receipt',
                'mappings' => json_encode([
                    'bank' => ['direction' => 'debit'],
                    'cash' => ['direction' => 'debit'],
                ]),
            ],
            // ACC-007 Fixed Assets — depreciation (DR Depreciation Expense, CR Accumulated Depreciation)
            [
                'source_capability' => 'ACC-007',
                'posting_profile_code' => 'ASSET_DEPRECIATION',
                'name' => 'Fixed Assets — Depreciation Run',
                'description' => 'Periodic depreciation: debit Depreciation Expense, credit Accumulated Depreciation',
                'mappings' => json_encode([
                    'depreciation_expense' => ['direction' => 'debit'],
                    'accumulated_depreciation' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-007 Fixed Assets — capitalisation (DR Asset, CR Clearing/Bank)
            [
                'source_capability' => 'ACC-007',
                'posting_profile_code' => 'ASSET_CAPITALISATION',
                'name' => 'Fixed Assets — Capitalisation',
                'description' => 'Capitalise an asset: debit Asset Cost, credit Clearing',
                'mappings' => json_encode([
                    'inventory' => ['direction' => 'debit'],
                    'cash' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-008 Currency & FX — revaluation
            [
                'source_capability' => 'ACC-008',
                'posting_profile_code' => 'FX_REVALUATION',
                'name' => 'Currency & FX — Revaluation',
                'description' => 'FX revaluation: debit/credit FX Unrealised + base-currency offset',
                'mappings' => json_encode([
                    'unrealized_fx_gain_loss' => ['direction' => 'debit'],
                    'realized_fx_gain_loss' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-009 Tax — pure tax posting (rare; usually embedded in AR/AP)
            [
                'source_capability' => 'ACC-009',
                'posting_profile_code' => 'TAX_ADJUSTMENT',
                'name' => 'Tax — Adjustment Entry',
                'description' => 'Tax adjustment: debit/credit Tax Collected/Input',
                'mappings' => json_encode([
                    'sales_tax_payable' => ['direction' => 'debit'],
                    'input_tax' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-011 Close — year-end closing entries
            [
                'source_capability' => 'ACC-011',
                'posting_profile_code' => 'CLOSE_PERIODIC',
                'name' => 'Financial Close — Periodic Closing',
                'description' => 'Year-end: zero out revenue/expense to Retained Earnings',
                'mappings' => json_encode([
                    'sales_revenue' => ['direction' => 'debit'],
                    'general_expense' => ['direction' => 'credit'],
                    'retained_earnings' => ['direction' => 'credit'],
                ]),
            ],
            // ACC-005A Refund — payment refund reversal
            [
                'source_capability' => 'ACC-005A',
                'posting_profile_code' => 'PAYMENT_REFUND',
                'name' => 'Payments — Refund',
                'description' => 'Refund: debit Receivable (or reverse payable), credit Bank',
                'mappings' => json_encode([
                    'accounts_receivable' => ['direction' => 'debit'],
                    'accounts_payable' => ['direction' => 'credit'],
                    'bank' => ['direction' => 'credit'],
                ]),
            ],
        ];

        foreach ($profiles as $profile) {
            DB::table('accounting_posting_profiles')->updateOrInsert(
                [
                    'tenant_id' => null, // global catalog
                    'source_capability' => $profile['source_capability'],
                    'posting_profile_code' => $profile['posting_profile_code'],
                    'version' => '1.0',
                ],
                [
                    'entity_id' => null,
                    'accounting_book_id' => null,
                    'name' => $profile['name'],
                    'description' => $profile['description'],
                    'mappings' => $profile['mappings'],
                    'control_account_requirements' => $profile['control_account_requirements'] ?? null,
                    'validation_requirements' => null,
                    'is_active' => true,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
