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
    }

    /**
     * Get summary metrics
     */
    protected function getSummary($startDate)
    {
        $transactions = PaymentTransaction::when($startDate, function ($query) use ($startDate) {
            return $query->where('created_at', '>=', $startDate);
        });

        $completed = clone $transactions;
        $failed = clone $transactions;
        $refunded = clone $transactions;

        return [
            'total_revenue' => $completed->where('status', 'completed')->sum('amount'),
            'total_transactions' => $transactions->count(),
            'successful_transactions' => $completed->where('status', 'completed')->count(),
            'failed_transactions' => $failed->where('status', 'failed')->count(),
            'refunded_amount' => $refunded->sum('refunded_amount'),
            'average_transaction_value' => $completed->where('status', 'completed')->avg('amount') ?? 0,
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
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    /**
     * Get success rate percentage
     */
    protected function getSuccessRate($startDate)
    {
        $total = PaymentTransaction::when($startDate, function ($query) use ($startDate) {
            return $query->where('created_at', '>=', $startDate);
        })->count();

        if ($total === 0) {
            return 0;
        }

        $successful = PaymentTransaction::where('status', 'completed')
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * Get top performing payment gateways
     */
    protected function getTopGateways($startDate)
    {
        return PaymentTransaction::select(
            'payment_gateway_id',
            DB::raw('COUNT(*) as transactions'),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue"),
            DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful"),
            DB::raw("ROUND((SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)::numeric / COUNT(*)) * 100, 2) as success_rate")
        )
            ->with('gateway:id,gateway_name')
            ->when($startDate, function ($query) use ($startDate) {
                return $query->where('created_at', '>=', $startDate);
            })
            ->groupBy('payment_gateway_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();
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
            ->with(['invoice:id,invoice_number', 'gateway:id,gateway_name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'transaction_id', 'amount', 'currency', 'error_message', 'invoice_id', 'payment_gateway_id', 'created_at']);
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
