<?php

namespace Modules\FinancialReporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\FinancialReporting\Models\ReportSchedule;
use Modules\FinancialReporting\Services\Reports\ProfitLossService;
use Modules\FinancialReporting\Services\Reports\BalanceSheetService;
use Modules\FinancialReporting\Services\Reports\CashFlowService;
use Modules\FinancialReporting\Services\Reports\TrialBalanceService;
use Modules\FinancialReporting\Services\Reports\GeneralLedgerService;
use Modules\FinancialReporting\Services\Inventory\StockValuationService;
use Modules\FinancialReporting\Services\Inventory\StockMovementService;
use Modules\FinancialReporting\Services\Export\PdfExportService;
use Modules\FinancialReporting\Services\Export\ExcelExportService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

/**
 * Job to generate scheduled reports
 * Handles report generation, export, and email delivery
 */
class GenerateScheduledReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ReportSchedule $schedule;

    /**
     * Create a new job instance.
     */
    public function __construct(ReportSchedule $schedule)
    {
        $this->schedule = $schedule;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Generating scheduled report", [
                'schedule_id' => $this->schedule->id,
                'report_type' => $this->schedule->report_type,
            ]);

            // Generate the report
            $reportData = $this->generateReport();

            // Export the report
            $filePath = $this->exportReport($reportData);

            // Send email notifications
            $this->sendNotifications($filePath);

            // Update schedule
            $this->updateSchedule();

            Log::info("Scheduled report generated successfully", [
                'schedule_id' => $this->schedule->id,
                'file_path' => $filePath,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to generate scheduled report", [
                'schedule_id' => $this->schedule->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate the report based on type
     */
    protected function generateReport(): array
    {
        $parameters = $this->schedule->parameters ?? [];
        $filter = $this->createReportFilter($parameters);

        return match ($this->schedule->report_type) {
            'profit_loss' => $this->generateProfitLoss($filter),
            'balance_sheet' => $this->generateBalanceSheet($filter),
            'cash_flow' => $this->generateCashFlow($filter),
            'trial_balance' => $this->generateTrialBalance($filter),
            'general_ledger' => $this->generateGeneralLedger($filter),
            'stock_valuation' => $this->generateStockValuation($filter),
            'stock_movement' => $this->generateStockMovement($filter),
            default => throw new \InvalidArgumentException("Unknown report type: {$this->schedule->report_type}"),
        };
    }

    /**
     * Create report filter from parameters
     */
    protected function createReportFilter(array $parameters): ReportFilter
    {
        $filter = new ReportFilter();

        // Set date range based on frequency
        $dateRange = $this->getDateRangeForFrequency();
        $filter->startDate = $dateRange['start'];
        $filter->endDate = $dateRange['end'];

        // Apply custom parameters
        if (isset($parameters['department_id'])) {
            $filter->departmentId = $parameters['department_id'];
        }

        if (isset($parameters['account_id'])) {
            $filter->accountId = $parameters['account_id'];
        }

        if (isset($parameters['compare_period'])) {
            $filter->comparePeriod = $parameters['compare_period'];
        }

        return $filter;
    }

    /**
     * Get date range based on schedule frequency
     */
    protected function getDateRangeForFrequency(): array
    {
        $now = Carbon::now();

        return match ($this->schedule->frequency) {
            'daily' => [
                'start' => $now->copy()->subDay()->startOfDay(),
                'end' => $now->copy()->subDay()->endOfDay(),
            ],
            'weekly' => [
                'start' => $now->copy()->subWeek()->startOfWeek(),
                'end' => $now->copy()->subWeek()->endOfWeek(),
            ],
            'monthly' => [
                'start' => $now->copy()->subMonth()->startOfMonth(),
                'end' => $now->copy()->subMonth()->endOfMonth(),
            ],
            'quarterly' => [
                'start' => $now->copy()->subQuarter()->startOfQuarter(),
                'end' => $now->copy()->subQuarter()->endOfQuarter(),
            ],
            'yearly' => [
                'start' => $now->copy()->subYear()->startOfYear(),
                'end' => $now->copy()->subYear()->endOfYear(),
            ],
            default => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
            ],
        };
    }

    /**
     * Generate Profit & Loss report
     */
    protected function generateProfitLoss(ReportFilter $filter): array
    {
        $service = app(ProfitLossService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'profit_loss',
            'title' => 'Profit & Loss Statement',
            'period' => $filter->startDate->format('Y-m-d') . ' to ' . $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Generate Balance Sheet report
     */
    protected function generateBalanceSheet(ReportFilter $filter): array
    {
        $service = app(BalanceSheetService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'balance_sheet',
            'title' => 'Balance Sheet',
            'as_of_date' => $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Generate Cash Flow report
     */
    protected function generateCashFlow(ReportFilter $filter): array
    {
        $service = app(CashFlowService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'cash_flow',
            'title' => 'Cash Flow Statement',
            'period' => $filter->startDate->format('Y-m-d') . ' to ' . $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Generate Trial Balance report
     */
    protected function generateTrialBalance(ReportFilter $filter): array
    {
        $service = app(TrialBalanceService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'trial_balance',
            'title' => 'Trial Balance',
            'as_of_date' => $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Generate General Ledger report
     */
    protected function generateGeneralLedger(ReportFilter $filter): array
    {
        $service = app(GeneralLedgerService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'general_ledger',
            'title' => 'General Ledger',
            'period' => $filter->startDate->format('Y-m-d') . ' to ' . $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Generate Stock Valuation report
     */
    protected function generateStockValuation(ReportFilter $filter): array
    {
        $service = app(StockValuationService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'stock_valuation',
            'title' => 'Stock Valuation Report',
            'as_of_date' => $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Generate Stock Movement report
     */
    protected function generateStockMovement(ReportFilter $filter): array
    {
        $service = app(StockMovementService::class);
        $data = $service->generate($filter);

        return [
            'type' => 'stock_movement',
            'title' => 'Stock Movement Report',
            'period' => $filter->startDate->format('Y-m-d') . ' to ' . $filter->endDate->format('Y-m-d'),
            'data' => $data->toArray(),
        ];
    }

    /**
     * Export the report to file
     */
    protected function exportReport(array $reportData): string
    {
        $parameters = $this->schedule->parameters ?? [];
        $format = $parameters['export_format'] ?? 'pdf';

        $filename = sprintf(
            '%s_%s_%s.%s',
            $this->schedule->report_type,
            $this->schedule->frequency,
            Carbon::now()->format('Y-m-d_His'),
            $format
        );

        if ($format === 'pdf') {
            $service = app(PdfExportService::class);
            $filePath = $service->exportReport($reportData, $this->schedule->report_type);
        } else {
            $service = app(ExcelExportService::class);
            $filePath = $service->exportReport($reportData, $this->schedule->report_type);
        }

        // Move to scheduled reports directory
        $scheduledPath = "reports/scheduled/{$filename}";
        Storage::move($filePath, $scheduledPath);

        return $scheduledPath;
    }

    /**
     * Send email notifications to recipients
     */
    protected function sendNotifications(string $filePath): void
    {
        $recipients = $this->schedule->recipients ?? [];

        if (empty($recipients)) {
            Log::warning("No recipients configured for scheduled report", [
                'schedule_id' => $this->schedule->id,
            ]);
            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::send('financial-reporting::emails.scheduled-report', [
                    'schedule' => $this->schedule,
                    'report_name' => $this->schedule->name,
                    'report_type' => $this->schedule->report_type,
                    'frequency' => $this->schedule->frequency,
                ], function ($message) use ($recipient, $filePath) {
                    $message->to($recipient)
                        ->subject("Scheduled Report: {$this->schedule->name}")
                        ->attach(Storage::path($filePath));
                });

                Log::info("Scheduled report email sent", [
                    'schedule_id' => $this->schedule->id,
                    'recipient' => $recipient,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to send scheduled report email", [
                    'schedule_id' => $this->schedule->id,
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Update schedule after successful generation
     */
    protected function updateSchedule(): void
    {
        $this->schedule->last_run_at = Carbon::now();
        $this->schedule->next_run_at = $this->calculateNextRunTime();
        $this->schedule->save();
    }

    /**
     * Calculate next run time based on frequency
     */
    protected function calculateNextRunTime(): Carbon
    {
        $now = Carbon::now();

        return match ($this->schedule->frequency) {
            'daily' => $now->addDay(),
            'weekly' => $now->addWeek(),
            'monthly' => $now->addMonth(),
            'quarterly' => $now->addQuarter(),
            'yearly' => $now->addYear(),
            default => $now->addMonth(),
        };
    }
}
