<?php

namespace Modules\POS\Models;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PosOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pos_orders';

    protected $fillable = [
        'external_id',
        'order_number',
        'session_id',
        'terminal_id',
        'customer_id',
        'status',
        'subtotal',
        'tax_amount',
        'total',
        'discount_type',
        'discount_value',
        'discount_amount',
        'currency',
        'notes',
        'journal_entry_id',
        'created_by',
        'paid_at',
        'is_return',
        'original_order_id',
        'loyalty_points_earned',
        'loyalty_points_redeemed',
        'loyalty_discount',
        'receipt_number',
        'cashier_name',
        'payment_state',
        'payment_method',
        'amount_tendered',
        'change_due',
        'voided_by',
        'void_approved_by',
        'void_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'loyalty_discount' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_due' => 'decimal:2',
        'paid_at' => 'datetime',
        'is_return' => 'boolean',
        'loyalty_points_earned' => 'integer',
        'loyalty_points_redeemed' => 'integer',
    ];

    // ==========================================
    // STATE MACHINE
    // ==========================================

    /**
     * Allowed state transitions
     */
    protected static array $allowedTransitions = [
        'draft' => ['paid', 'void', 'cancelled'],
        'paid' => ['void', 'refunded', 'returned'],
        'void' => [],
        'refunded' => [],
        'returned' => [],
        'cancelled' => [],
    ];

    /**
     * Validate state transition
     *
     * @throws \InvalidArgumentException
     */
    public function validateTransition(string $newStatus): void
    {
        $currentStatus = $this->status;

        if (! isset(self::$allowedTransitions[$currentStatus])) {
            throw new \InvalidArgumentException("Invalid current status: {$currentStatus}");
        }

        if (! in_array($newStatus, self::$allowedTransitions[$currentStatus])) {
            throw new \InvalidArgumentException(
                "Invalid state transition from '{$currentStatus}' to '{$newStatus}'. ".
                'Allowed transitions: '.implode(', ', self::$allowedTransitions[$currentStatus])
            );
        }
    }

    /**
     * Transition to new status with validation
     *
     * @throws \InvalidArgumentException
     */
    public function transitionTo(string $newStatus): void
    {
        $this->validateTransition($newStatus);
        $this->status = $newStatus;
    }

    /**
     * Check if transition is allowed
     */
    public function canTransitionTo(string $newStatus): bool
    {
        try {
            $this->validateTransition($newStatus);

            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosOrderItem::class, 'pos_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class, 'pos_order_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'original_order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'original_order_id');
    }

    // ==========================================
    // METHODS
    // ==========================================

    /**
     * @deprecated Legacy loyalty processing removed. Loyalty points are now processed
     * by PosOrderController using canonical Loyalty module services.
     *
     * This method is kept as a no-op to prevent breaking existing code that calls it.
     * All loyalty processing now happens in PosOrderController::processPayment()
     * using PointsEarningService and RedemptionService.
     */
    public function processLoyaltyPoints(): void
    {
        // No-op: Loyalty processing moved to PosOrderController
        // Update customer stats only
        if ($this->customer_id) {
            $this->customer->updatePurchaseStats();
        }
    }

    /**
     * Create a return order
     */
    public function createReturn(array $items, ?string $notes = null): PosOrder
    {
        $returnOrder = static::create([
            'external_id' => (string) Str::uuid(),
            'order_number' => $this->order_number.'-R'.($this->returns()->count() + 1),
            'session_id' => $this->session_id,
            'terminal_id' => $this->terminal_id,
            'customer_id' => $this->customer_id,
            'status' => 'paid',
            'is_return' => true,
            'original_order_id' => $this->id,
            'created_by' => TenantUserHelper::resolveTenantUserId(),
            'cashier_name' => auth()->user()?->name,
            'notes' => $notes,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $subtotal = 0;
        $tax = 0;

        foreach ($items as $item) {
            $originalItem = $this->items()->find($item['item_id']);
            if (! $originalItem) {
                continue;
            }

            $qty = $item['quantity'];
            $itemTotal = $originalItem->unit_price * $qty;
            $itemTax = $originalItem->tax_amount / $originalItem->quantity * $qty;

            $returnOrder->items()->create([
                'product_id' => $originalItem->product_id,
                'name' => $originalItem->name,
                'sku' => $originalItem->sku,
                'quantity' => -$qty,
                'unit_price' => $originalItem->unit_price,
                'tax_rate' => $originalItem->tax_rate,
                'tax_amount' => -$itemTax,
                'line_subtotal' => -round($originalItem->unit_price * $qty, 2),
                'line_total' => -round($itemTotal + $itemTax, 2),
            ]);

            $subtotal += $itemTotal;
            $tax += $itemTax;
        }

        $returnOrder->subtotal = -$subtotal;
        $returnOrder->tax_amount = -$tax;
        $returnOrder->total = -($subtotal + $tax);
        $returnOrder->save();

        return $returnOrder;
    }

    /**
     * Generate receipt number
     */
    public static function generateReceiptNumber(): string
    {
        $date = now()->format('Ymd');
        $last = static::where('receipt_number', 'like', "R{$date}%")
            ->orderBy('receipt_number', 'desc')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->receipt_number, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return 'R'.$date.str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    protected static function boot()
    {
        parent::boot();

        // Don't auto-generate receipt on create - it's generated when order is PAID
        // static::creating handled separately

        static::updated(function ($order) {
            // Process loyalty when order is marked as paid
            if ($order->status === 'paid' && $order->wasChanged('status') && ! $order->is_return) {
                // Loyalty is now processed by the controller
            }
        });
    }
}
