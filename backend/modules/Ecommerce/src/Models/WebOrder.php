<?php

namespace Modules\Ecommerce\Models;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\StockReservation;

class WebOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'web_orders';

    protected $fillable = [
        'order_number',
        'customer_id',
        'email',
        'phone',
        'first_name',
        'last_name',
        'shipping_address',
        'status',
        'payment_status',
        'subtotal',
        'shipping_cost',
        'tax_amount',
        'discount_amount',
        'loyalty_discount',
        'total',
        'currency',
        'invoice_id',
        'fulfillment_warehouse_id',
        'payment_transaction_id',
        'loyalty_points_earned',
        'loyalty_points_redeemed',
        'user_agent',
        'ip_address',
        'notes',
        'guest_token',
        'confirmed_at',
        'paid_at',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'loyalty_discount' => 'decimal:2',
        'total' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    // Relationships
    public function items(): HasMany
    {
        return $this->hasMany(WebOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fulfillmentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'fulfillment_warehouse_id');
    }

    // ── Status constants ──────────────────────────────────────────────────────

    public const STATUS_PENDING    = 'pending';
    public const STATUS_CONFIRMED  = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED    = 'shipped';
    public const STATUS_DELIVERED  = 'delivered';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_REFUNDED   = 'refunded';

    public const PAYMENT_STATUS_PENDING  = 'pending';
    public const PAYMENT_STATUS_PAID     = 'paid';
    public const PAYMENT_STATUS_FAILED   = 'failed';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';

    // ── Valid status transitions (state machine) ──────────────────────────────

    private const VALID_TRANSITIONS = [
        self::STATUS_PENDING    => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED  => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_SHIPPED, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED    => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED  => [self::STATUS_REFUNDED],
        self::STATUS_CANCELLED  => [],
        self::STATUS_REFUNDED   => [],
    ];

    // ── State machine methods ─────────────────────────────────────────────────

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): self
    {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid transition from '{$this->status}' to '{$newStatus}'. "
                . 'Allowed: ' . implode(', ', self::VALID_TRANSITIONS[$this->status] ?? ['none'])
            );
        }

        $this->status = $newStatus;

        $timestampField = match ($newStatus) {
            self::STATUS_CONFIRMED  => 'confirmed_at',
            self::STATUS_SHIPPED    => 'shipped_at',
            self::STATUS_DELIVERED  => 'delivered_at',
            default => null,
        };

        if ($timestampField) {
            $this->{$timestampField} = now();
        }

        $this->save();

        return $this;
    }

    public function confirm(): self
    {
        return $this->transitionTo(self::STATUS_CONFIRMED);
    }

    public function cancel(): self
    {
        return $this->transitionTo(self::STATUS_CANCELLED);
    }

    public function markProcessing(): self
    {
        return $this->transitionTo(self::STATUS_PROCESSING);
    }

    public function markShipped(): self
    {
        return $this->transitionTo(self::STATUS_SHIPPED);
    }

    public function markDelivered(): self
    {
        return $this->transitionTo(self::STATUS_DELIVERED);
    }

    public function markRefunded(): self
    {
        return $this->transitionTo(self::STATUS_REFUNDED);
    }

    public function markPaid(): self
    {
        if ($this->payment_status !== self::PAYMENT_STATUS_PENDING) {
            throw new \InvalidArgumentException(
                "Cannot mark as paid. Current payment status: '{$this->payment_status}'"
            );
        }

        $this->payment_status = self::PAYMENT_STATUS_PAID;
        $this->paid_at = now();
        $this->save();

        return $this;
    }

    public function resolveInventoryWarehouseId(): ?int
    {
        if ($this->fulfillment_warehouse_id) {
            return (int) $this->fulfillment_warehouse_id;
        }

        $reservationWarehouseIds = StockReservation::query()
            ->where('source_document_type', 'WebOrder')
            ->where('source_document_id', $this->id)
            ->whereNotNull('warehouse_id')
            ->distinct()
            ->pluck('warehouse_id');

        if ($reservationWarehouseIds->count() === 1) {
            return (int) $reservationWarehouseIds->first();
        }

        $movementWarehouseIds = StockMovement::query()
            ->where('reference_type', 'WebOrder')
            ->where('reference_id', $this->id)
            ->whereNotNull('warehouse_id')
            ->distinct()
            ->pluck('warehouse_id');

        if ($movementWarehouseIds->count() === 1) {
            return (int) $movementWarehouseIds->first();
        }

        return null;
    }

    // Generate unique order number
    public static function generateOrderNumber(): string
    {
        $prefix = 'WEB-' . now()->format('Y') . '-';
        $lastOrder = static::where('order_number', 'like', $prefix . '%')
            ->orderBy('order_number', 'desc')
            ->first();

        $number = 1;
        if ($lastOrder) {
            $parts = explode('-', $lastOrder->order_number);
            $number = (int) end($parts) + 1;
        }

        return $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    // Convert WebOrder to Invoice via canonical SalesOrderService
    public function convertToInvoice(): Invoice
    {
        /** @var \Modules\Sales\Services\SalesOrderService $salesOrderService */
        $salesOrderService = app(\Modules\Sales\Services\SalesOrderService::class);

        // Create SalesOrder (idempotent via SalesChannelReference)
        $salesOrder = $salesOrderService->createFromWebOrder($this);

        // If already invoiced, retrieve existing invoice
        if ($salesOrder->isInvoiced()) {
            $link = \Modules\Sales\Models\SalesInvoiceLink::where('sales_order_id', $salesOrder->id)
                ->where('link_state', 'active')
                ->first();
            if ($link) {
                $invoice = Invoice::findOrFail($link->invoice_id);
                // Ensure web order is linked
                if (!$this->invoice_id) {
                    $this->update(['invoice_id' => $invoice->id]);
                }
                return $invoice;
            }
        }

        // Fast-track through lifecycle for already-paid web order
        if ($salesOrder->isDraft()) {
            $salesOrder = $salesOrderService->confirmOrder($salesOrder);
        }
        if ($salesOrder->isConfirmed()) {
            $salesOrder = $salesOrderService->allocateOrder($salesOrder);
        }
        if ($salesOrder->isAllocated()) {
            $salesOrder = $salesOrderService->fulfilOrder($salesOrder);
        }

        // Create invoice via canonical SalesOrderService → InvoiceService path
        $invoice = $salesOrderService->createInvoice($salesOrder);

        // Override totals to match the already-paid web order and reconcile
        // invoice balances via InvoiceService without posting duplicate GL.
        $invoice->update([
            'subtotal'        => $this->subtotal,
            'tax_amount'      => $this->tax_amount,
            'discount_amount' => $this->discount_amount + $this->loyalty_discount,
            'total'           => $this->total,
            'web_order_id'    => $this->id,
        ]);

        app(\App\Services\InvoiceService::class)->recordChannelPayment($invoice->fresh(), [
            'payment_date' => now(),
            'method' => 'card',
            'reference' => "WEB-{$this->order_number}",
            'amount' => (float) $this->total,
            'notes' => "Web order {$this->order_number} (pre-paid)",
            'received_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId($this->customer_id),
        ]);

        // Update order with invoice reference
        $this->update(['invoice_id' => $invoice->id]);

        // Update redemption with invoice_id if points were redeemed
        if ($this->loyalty_points_redeemed > 0) {
            $loyaltyTransaction = \Modules\Loyalty\Models\LoyaltyTransaction::where('reference_type', 'web_order')
                ->where('reference_id', $this->id)
                ->where('type', 'redeem')
                ->first();
            
            if ($loyaltyTransaction) {
                \Modules\Loyalty\Models\LoyaltyRedemption::where('loyalty_transaction_id', $loyaltyTransaction->id)
                    ->update(['invoice_id' => $invoice->id]);
            }
        }

        return $invoice;
    }
}
