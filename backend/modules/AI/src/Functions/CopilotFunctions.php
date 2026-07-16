<?php

namespace Modules\AI\Functions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CopilotFunctions
{
    public static function detectCloseAnomalies(array $args): array
    {
        try {
            Log::info('AI Copilot: detectCloseAnomalies called', ['user_id' => auth()->id(), 'args' => $args]);

            $period = $args['period'] ?? 'this_year';

            $startDate = match ($period) {
                'this_year' => now()->startOfYear(),
                'last_month' => now()->subMonth()->startOfMonth(),
                default => now()->startOfMonth(),
            };
            $endDate = match ($period) {
                'this_year' => now()->endOfYear(),
                'last_month' => now()->subMonth()->endOfMonth(),
                default => now()->endOfMonth(),
            };

            $entries = DB::table('journal_entries as je')
                ->join('journal_entry_lines as jel', 'je.id', '=', 'jel.journal_entry_id')
                ->whereBetween('je.entry_date', [$startDate, $endDate])
                ->whereNull('je.deleted_at')
                ->groupBy('je.id', 'je.entry_number', 'je.entry_date', 'je.type', 'je.status')
                ->get([
                    'je.id',
                    'je.entry_number',
                    'je.entry_date',
                    'je.type',
                    'je.status',
                    DB::raw('SUM(jel.debit) as total_debit'),
                    DB::raw('SUM(jel.credit) as total_credit'),
                ]);

            $anomalies = [];
            $avgAmount = $entries->avg('total_debit');

            foreach ($entries as $entry) {
                $reasons = [];

                if (abs($entry->total_debit - $entry->total_credit) > 0.01) {
                    $reasons[] = 'Unbalanced entry - debit and credit do not match';
                }

                if ($avgAmount > 0 && $entry->total_debit > ($avgAmount * 3)) {
                    $reasons[] = 'Unusually large amount compared to average';
                }

                if ($entry->status === 'draft') {
                    $reasons[] = 'Entry still in draft status - may need review';
                }

                if (! empty($reasons)) {
                    $anomalies[] = [
                        'entry_number' => $entry->entry_number,
                        'entry_date' => $entry->entry_date,
                        'type' => $entry->type,
                        'status' => $entry->status,
                        'total_debit' => $entry->total_debit,
                        'total_credit' => $entry->total_credit,
                        'reasons' => $reasons,
                    ];
                }
            }

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'detectCloseAnomalies',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (empty($anomalies)) {
                return [
                    'success' => true,
                    'found' => false,
                    'period' => $period,
                    'message' => 'No anomalies detected for the selected period.',
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'period' => $period,
                'anomaly_count' => count($anomalies),
                'anomalies' => $anomalies,
            ];

        } catch (\Exception $e) {
            Log::error('CopilotFunctions::detectCloseAnomalies failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to detect anomalies.',
            ];
        }
    }

    public static function prioritizeCollections(array $args): array
    {
        try {
            Log::info('AI Copilot: prioritizeCollections called', ['user_id' => auth()->id(), 'args' => $args]);

            $limit = $args['limit'] ?? 10;

            $customers = DB::table('customers as c')
                ->leftJoin('invoices as i', function ($join) {
                    $join->on('i.customer_id', '=', 'c.id')
                        ->whereNull('i.deleted_at');
                })
                ->whereNull('c.deleted_at')
                ->having(DB::raw('SUM(i.balance)'), '>', 0)
                ->groupBy('c.id', 'c.name', 'c.email', 'c.payment_terms')
                ->orderByRaw('SUM(i.balance) DESC')
                ->limit($limit)
                ->get([
                    'c.id',
                    'c.name',
                    'c.email',
                    'c.payment_terms',
                    DB::raw('COUNT(i.id) as invoice_count'),
                    DB::raw('SUM(i.total) as total_invoiced'),
                    DB::raw('SUM(i.balance) as total_balance'),
                    DB::raw('SUM(CASE WHEN i.due_date < NOW() AND i.balance > 0 THEN i.balance ELSE 0 END) as overdue_balance'),
                ]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'prioritizeCollections',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($customers->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No customers with outstanding balances found.',
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'count' => $customers->count(),
                'customers' => $customers->toArray(),
            ];

        } catch (\Exception $e) {
            Log::error('CopilotFunctions::prioritizeCollections failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to prioritize collections.',
            ];
        }
    }

    public static function suggestAPCoding(array $args): array
    {
        try {
            Log::info('AI Copilot: suggestAPCoding called', ['user_id' => auth()->id(), 'args' => $args]);

            // Get recent posted OCR documents with their account mappings
            $documents = DB::table('ocr_documents as od')
                ->join('chart_of_accounts as coa', DB::raw("(od.line_items::jsonb->0->>'account_id')::bigint"), '=', 'coa.id')
                ->where('od.status', 'POSTED')
                ->whereNull('od.deleted_at')
                ->orderBy('od.created_at', 'desc')
                ->limit(10)
                ->get([
                    'od.document_reference',
                    'od.vendor_name',
                    'od.total_amount',
                    'od.document_date',
                    'coa.id as account_id',
                    'coa.name as account_name',
                    'coa.code as account_code',
                    'coa.type as account_type',
                ]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'suggestAPCoding',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($documents->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No past documents found to base suggestions on.',
                ];
            }

            // Find most commonly used account
            $accountCounts = $documents->groupBy('account_id')->map->count();
            $topAccountId = $accountCounts->sortDesc()->keys()->first();
            $topAccount = $documents->firstWhere('account_id', $topAccountId);

            return [
                'success' => true,
                'found' => true,
                'suggested_account' => [
                    'account_id' => $topAccount->account_id,
                    'account_name' => $topAccount->account_name,
                    'account_code' => $topAccount->account_code,
                    'account_type' => $topAccount->account_type,
                ],
                'based_on_documents' => $documents->count(),
                'recent_documents' => $documents->toArray(),
            ];

        } catch (\Exception $e) {
            Log::error('CopilotFunctions::suggestAPCoding failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve AP coding suggestions.',
            ];
        }
    }

    public static function reviewRevenue(array $args): array
    {
        try {
            Log::info('AI Copilot: reviewRevenue called', ['user_id' => auth()->id(), 'args' => $args]);

            $invoices = DB::table('invoices')
                ->whereNull('deleted_at')
                ->get([
                    'id',
                    'invoice_number',
                    'invoice_date',
                    'due_date',
                    'total',
                    'balance',
                    'status',
                    'customer_id',
                ]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'reviewRevenue',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($invoices->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No invoice data found to review.',
                ];
            }

            $avgTotal = $invoices->avg('total');
            $findings = [];

            foreach ($invoices as $invoice) {
                $risks = [];

                if ($invoice->total > ($avgTotal * 2.5) && $invoice->balance > 0) {
                    $risks[] = 'Unusually large invoice with outstanding balance';
                }

                if ($invoice->balance > 0 && $invoice->due_date < now()->toDateString()) {
                    $risks[] = 'Overdue invoice with outstanding balance';
                }

                if ($invoice->status === 'draft') {
                    $risks[] = 'Invoice still in draft status';
                }

                if (! empty($risks)) {
                    $findings[] = [
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_date' => $invoice->invoice_date,
                        'due_date' => $invoice->due_date,
                        'total' => $invoice->total,
                        'balance' => $invoice->balance,
                        'status' => $invoice->status,
                        'risks' => $risks,
                    ];
                }
            }

            if (empty($findings)) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No revenue anomalies or risks detected.',
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'finding_count' => count($findings),
                'average_total' => round($avgTotal, 2),
                'findings' => $findings,
            ];

        } catch (\Exception $e) {
            Log::error('CopilotFunctions::reviewRevenue failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to review revenue.',
            ];
        }
    }
}
