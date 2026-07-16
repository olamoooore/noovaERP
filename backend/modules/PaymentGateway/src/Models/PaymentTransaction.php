<?php

namespace Modules\PaymentGateway\Models;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_gateway_id',
        'invoice_id',
        'customer_id',
        'transaction_id',
        'webhook_id',
        'amount',
        'refunded_amount',
        'refund_reason',
        'currency',
        'status',
        'payment_method',
        'gateway_response',
        'metadata',
        'processed_at',
        'refunded_at',
        'webhook_received_at',
        'webhook_attempts',
        'error_message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'webhook_received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (self $transaction): void {
            $transaction->syncWebhookTenantLookup();
        });

        static::deleted(function (self $transaction): void {
            $transaction->deleteWebhookTenantLookup();
        });
    }

    public static function webhookTenantLookupBaseTable(): string
    {
        return 'payment_webhook_tenant_lookups';
    }

    public static function webhookTenantLookupTable(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'public.'.static::webhookTenantLookupBaseTable()
            : static::webhookTenantLookupBaseTable();
    }

    public function syncWebhookTenantLookup(): void
    {
        if (! function_exists('tenant') || ! tenant()) {
            return;
        }

        if (! Schema::hasTable(static::webhookTenantLookupBaseTable())) {
            return;
        }

        $gatewayName = $this->relationLoaded('gateway')
            ? $this->gateway?->gateway_name
            : $this->gateway()->value('gateway_name');

        if (! $gatewayName || ! $this->transaction_id) {
            return;
        }

        $timestamp = now();

        DB::table(static::webhookTenantLookupTable())->upsert(
            [[
                'gateway_name' => $gatewayName,
                'transaction_id' => $this->transaction_id,
                'tenant_id' => (string) tenant()->getTenantKey(),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]],
            ['gateway_name', 'transaction_id'],
            ['tenant_id', 'updated_at']
        );
    }

    public function deleteWebhookTenantLookup(): void
    {
        if (! Schema::hasTable(static::webhookTenantLookupBaseTable()) || ! $this->transaction_id) {
            return;
        }

        $gatewayName = $this->relationLoaded('gateway')
            ? $this->gateway?->gateway_name
            : $this->gateway()->value('gateway_name');

        if (! $gatewayName) {
            return;
        }

        DB::table(static::webhookTenantLookupTable())
            ->where('gateway_name', $gatewayName)
            ->where('transaction_id', $this->transaction_id)
            ->delete();
    }

    /**
     * Get the route key for the model.
     * Use transaction_id instead of id for route model binding
     */
    public function getRouteKeyName(): string
    {
        return 'transaction_id';
    }

    /**
     * Get the payment gateway
     */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * Get the associated invoice
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Scope for successful transactions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for failed transactions
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Check if transaction is successful
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if transaction is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if transaction failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark transaction as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark transaction as failed
     */
    public function markAsFailed(?string $errorMessage = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'processed_at' => now(),
        ]);
    }
}
