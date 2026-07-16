<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\PaymentGateway\Models\PaymentTransaction;

class PaymentAnalyticsController extends Controller
{
    /**
     * Get payment analytics dashboard data
     */
    public function index(Request $request)
    {
        try {
            $period = $request->get('period', '30days'); // 7days, 30days, 90days, 1year, all
            $startDate = $this->getStartDate($period);

            return response()->json([
                'data' => [
                    'summary' => $this->getSummary($startDate),
                    'revenue_trend' => $this->getRevenueTrend($startDate, $period),
                    'success_rate' => $this->getSuccessRate($startDate),
                    'top_gateways' => $this->getTopGateways($startDate),
                    'payment_methods' => $this->getPaymentMethods($startDate),
                    'failed_payments' => $this->getFailedPayments($startDate),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Payment analytics error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to load analytics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get summary metrics
     */
    protected function getSummary($startDate)
    {
        $query = PaymentTransaction::when($startDate, function ($query) use ($startDate) {
            return $query->where('created_at', '>=', $startDate);
        });

        $totalRevenue = (clone $query)->where('status', 'completed')->sum('amount');
        $totalTransactions = (clone $query)->count();
        $successfulTransactions = (clone $query)->where('status', 'completed')->count();
        $failedTransactions = (clone $query)->where('status', 'failed')->count();
        $refundedAmount = (clone $query)->sum('refunded_amount');
        $avgTransactionValue = (clone $query)->where('status', 'completed')->avg('amount') ?? 0;

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_transactions' => $totalTransactions,
            'successful_transactions' => $successfulTransactions,
            'failed_transactions' => $failedTransactions,
            'refunded_amount' => round($refundedAmount, 2),
            'average_transaction_value' => round($avgTransactionValue, 2),
        ];
    }

    /**
     * Get revenue trend over time
     */
    protected function getRevenueTrend($startDate, $period)
    {
        $groupBy = match ($period) {
            '7days' => 'DATE(created_at)',
            '30days' => 'DATE(created_at)',
            '90days' => 'YEARWEEK(created_at)',
            '1year' => 'DATE_FORMAT(created_at, "%Y-%m")',
            default => 'DATE(created_at)',
        };

        return PaymentTransaction::select(
            DB::raw("$groupBy as period"),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue"),
            DB::raw('COUNT(*) as transactions'),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful"),
            DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
        )
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->groupBy(DB::raw($groupBy))
            ->orderBy('period')
            ->get();
    }

    /**
     * Get success rate percentage
     */
    protected function getSuccessRate($startDate)
    {
        $query = PaymentTransaction::when($startDate, function ($query) use ($startDate) {
            return $query->where('created_at', '>=', $startDate);
        });

        $total = $query->count();

        if ($total === 0) {
            return 0;
        }

        $successful = (clone $query)->where('status', 'completed')->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * Get top performing payment gateways
     */
    protected function getTopGateways($startDate)
    {
        // Use a simpler query that works across different databases
        $results = PaymentTransaction::select(
            'payment_gateway_id',
            DB::raw('COUNT(*) as transactions'),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue"),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful")
        )
            ->with(['gateway:id,gateway_name'])
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->groupBy('payment_gateway_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        // Calculate success rate in PHP to avoid database-specific casting
        return $results->map(function ($item) {
            $successRate = $item->transactions > 0
                ? round(($item->successful / $item->transactions) * 100, 2)
                : 0;

            return [
                'payment_gateway_id' => $item->payment_gateway_id,
                'transactions' => $item->transactions,
                'revenue' => $item->revenue,
                'successful' => $item->successful,
                'success_rate' => $successRate,
                'gateway' => $item->gateway,
            ];
        });
    }

    /**
     * Get payment methods distribution
     */
    protected function getPaymentMethods($startDate)
    {
        return PaymentTransaction::select(
            'payment_method',
            DB::raw('COUNT(*) as count'),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue")
        )
            ->where('status', 'completed')
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * Get recent failed payments for analysis
     */
    protected function getFailedPayments($startDate)
    {
        return PaymentTransaction::where('status', 'failed')
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->with(['gateway:id,gateway_name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'transaction_id', 'amount', 'currency', 'error_message', 'payment_gateway_id', 'created_at']);
    }

    /**
     * Get start date based on period
     */
    protected function getStartDate($period)
    {
        return match ($period) {
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            '1year' => now()->subYear(),
            'all' => null,
            default => now()->subDays(30),
        };
    }
}
