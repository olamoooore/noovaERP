<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Services;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\AdminOrderAlert;
use App\Notifications\CustomerOrderConfirmation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Ecommerce\Models\WebOrder;

class WebOrderNotificationService
{
    public function dispatchPaidOrderNotifications(WebOrder $order): void
    {
        $order->loadMissing('items', 'invoice');

        try {
            $customerEmail = $order->email;

            if (! $customerEmail && $order->customer_id) {
                $customerEmail = Customer::find($order->customer_id)?->email;
            }

            if ($customerEmail) {
                Notification::route('mail', $customerEmail)
                    ->notify(new CustomerOrderConfirmation($order, 'Web'));
            } else {
                Log::warning('Skipping customer order confirmation for web order without email', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }

            $admins = User::whereHas('roles', function ($query) {
                $query->where('name', 'like', '%admin%');
            })->get();

            if ($admins->isEmpty()) {
                $fallbackAdmin = User::find(1);
                if ($fallbackAdmin) {
                    $admins = collect([$fallbackAdmin]);
                }
            }

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AdminOrderAlert($order, 'Web'));
            }
        } catch (\Throwable $e) {
            Log::error('Web order notification dispatch failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
