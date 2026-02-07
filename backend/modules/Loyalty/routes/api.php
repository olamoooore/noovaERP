<?php

use Illuminate\Support\Facades\Route;
use Modules\Loyalty\Http\Controllers\LoyaltyProgramController;
use Modules\Loyalty\Http\Controllers\CustomerLoyaltyController;
use Modules\Loyalty\Http\Controllers\LoyaltyRedemptionController;
use Modules\Loyalty\Http\Controllers\LoyaltyRewardController;
use Modules\Loyalty\Http\Controllers\LoyaltyDashboardController;

Route::middleware(['auth:sanctum', 'feature:loyalty'])->group(function () {
    // Health check
    Route::get('/loyalty/health', function () {
        return ['module' => 'loyalty', 'ok' => true];
    });

    // Dashboard
    Route::get('/loyalty/dashboard', [LoyaltyDashboardController::class, 'index']);

    // Loyalty Programs
    Route::prefix('loyalty/programs')->group(function () {
        Route::get('/', [LoyaltyProgramController::class, 'index']); // Get active program
        Route::get('/all', [LoyaltyProgramController::class, 'all']); // Get all programs (admin)
        Route::post('/', [LoyaltyProgramController::class, 'store']); // Create program (admin)
        Route::get('/{programId}', [LoyaltyProgramController::class, 'show']); // Get program details
        Route::put('/{programId}', [LoyaltyProgramController::class, 'update']); // Update program (admin)
        Route::get('/{programId}/tiers', [LoyaltyProgramController::class, 'tiers']); // Get tiers
        Route::post('/{programId}/tiers', [LoyaltyProgramController::class, 'storeTier']); // Create tier (admin)
        Route::get('/{programId}/statistics', [LoyaltyProgramController::class, 'statistics']); // Get stats
    });

    // Loyalty Tiers
    Route::prefix('loyalty/tiers')->group(function () {
        Route::put('/{tierId}', [LoyaltyProgramController::class, 'updateTier']); // Update tier (admin)
        Route::delete('/{tierId}', [LoyaltyProgramController::class, 'destroyTier']); // Delete tier (admin)
    });

    // Customer Loyalty
    Route::prefix('loyalty/customers')->group(function () {
        Route::get('/', [CustomerLoyaltyController::class, 'index']); // Get all customers
        Route::post('/{customerId}/enroll', [CustomerLoyaltyController::class, 'enroll']); // Enroll customer
        Route::get('/{customerId}', [CustomerLoyaltyController::class, 'show']); // Get customer loyalty info
        Route::get('/{customerId}/dashboard', [CustomerLoyaltyController::class, 'dashboard']); // Get dashboard
        Route::get('/{customerId}/transactions', [CustomerLoyaltyController::class, 'transactions']); // Get transactions
        Route::post('/{customerId}/adjust', [CustomerLoyaltyController::class, 'adjustPoints']); // Adjust points (admin)
    });

    // Points Earning
    Route::post('/loyalty/earn', [CustomerLoyaltyController::class, 'earnPoints']); // Award points

    // Redemptions
    Route::prefix('loyalty/redemptions')->group(function () {
        Route::get('/', [LoyaltyRedemptionController::class, 'index']); // Get all redemptions (admin)
        Route::post('/discount', [LoyaltyRedemptionController::class, 'redeemForDiscount']); // Redeem for discount
        Route::post('/reward', [LoyaltyRedemptionController::class, 'redeemForReward']); // Redeem for reward
        Route::post('/calculate', [LoyaltyRedemptionController::class, 'calculateRedemption']); // Calculate redemption
        Route::get('/pos/{customerId}', [LoyaltyRedemptionController::class, 'posRedemptionInfo']); // POS redemption info
        Route::get('/customers/{customerId}', [LoyaltyRedemptionController::class, 'history']); // Get history
        Route::get('/{redemptionId}', [LoyaltyRedemptionController::class, 'show']); // Get redemption details
        Route::put('/{redemptionId}/status', [LoyaltyRedemptionController::class, 'updateStatus']); // Update status (admin)
        Route::post('/{redemptionId}/cancel', [LoyaltyRedemptionController::class, 'cancel']); // Cancel redemption
    });

    // Rewards
    Route::prefix('loyalty/rewards')->group(function () {
        Route::get('/', [LoyaltyRewardController::class, 'index']); // Get all rewards
        Route::post('/', [LoyaltyRewardController::class, 'store']); // Create reward (admin)
        Route::get('/catalog/{customerId}', [LoyaltyRewardController::class, 'catalog']); // Get catalog for customer
        Route::get('/{rewardId}', [LoyaltyRewardController::class, 'show']); // Get reward details
        Route::put('/{rewardId}', [LoyaltyRewardController::class, 'update']); // Update reward (admin)
        Route::delete('/{rewardId}', [LoyaltyRewardController::class, 'destroy']); // Delete reward (admin)
    });
});

use Modules\Loyalty\Http\Controllers\WebLoyaltyController;
use Modules\Ecommerce\Http\Middleware\WebCustomerAuth;

// Web Shop Loyalty Routes (Customer Portal)
Route::middleware(WebCustomerAuth::class)->prefix('web-shop')->group(function () {
    Route::get('/loyalty/balance', [WebLoyaltyController::class, 'balance']);
    Route::get('/loyalty/history', [WebLoyaltyController::class, 'history']);
    Route::post('/loyalty/calculate', [WebLoyaltyController::class, 'calculate']);
});
