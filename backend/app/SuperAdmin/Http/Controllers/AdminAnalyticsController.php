<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\RevenueAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AdminAnalyticsController extends Controller
{
    public function __construct(
        private readonly RevenueAnalyticsService $analyticsService,
    ) {}

    /**
     * GET /admin/analytics/revenue
     *
     * Returns two separate metrics:
     *   - mrr: normalized recurring revenue from active subscriptions (true MRR)
     *   - revenue: cash collected in last 30 days (cash flow)
     */
    public function revenue(): JsonResponse
    {
        Gate::authorize('view-revenue-analytics');

        return response()->json([
            'data' => [
                'mrr' => $this->analyticsService->getMrr(),
                'revenue_last_30d' => $this->analyticsService->getRevenueLast30Days(),
                'arpu' => $this->analyticsService->getArpu(),
                'growth' => $this->analyticsService->getGrowth(),
                'churn' => $this->analyticsService->getChurnThisMonth(),
                'subscription_stats' => $this->analyticsService->getSubscriptionStats(),
            ],
        ]);
    }

    /** GET /admin/analytics/plans */
    public function plans(): JsonResponse
    {
        Gate::authorize('view-revenue-analytics');

        return response()->json([
            'data' => $this->analyticsService->getMrrByPlan(),
        ]);
    }
}
