<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Customer;
use App\Models\CustomerLoyaltyProgram;
use App\Models\JournalEntry;
use App\Models\User;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PosOrder extends Model
{
    use SoftDeletes;
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
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'loyalty_discount' => 'decimal:2',
        'paid_at' => 'datetime',
        'is_return' => 'boolean',
        'loyalty_points_earned' => 'integer',
        'loyalty_points_redeemed' => 'integer',
    ];

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
     * Process loyalty points for this order
     */
    public function processLoyaltyPoints(): void
    {
        if (!$this->customer_id) {
            return;
        }

        $customer = $this->customer;
        
        // Always update customer purchase stats regardless of loyalty program
        $customer->updatePurchaseStats();
        
        $program = CustomerLoyaltyProgram::where('is_active', true)->first();
        
        if (!$program) {
            return;
        }

        // Calculate and add earned points
        $points = $program->calculatePoints($this->total);
        
        if ($points > 0) {
            $customer->addLoyaltyPoints($points, 'pos_order', $this->id);
            $this->loyalty_points_earned = $points;
            $this->save();
        }
    }

    /**
     * Create a return order
     */
    public function createReturn(array $items, string $notes = null): PosOrder
    {
        $returnOrder = static::create([
            'order_number' => $this->order_number . '-R' . ($this->returns()->count() + 1),
            'session_id' => $this->session_id,
            'terminal_id' => $this->terminal_id,
            'customer_id' => $this->customer_id,
            'status' => 'completed',
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
            if (!$originalItem) continue;

            $qty = $item['quantity'];
            $itemTotal = $originalItem->unit_price * $qty;
            $itemTax = $originalItem->tax_amount / $originalItem->quantity * $qty;

            $returnOrder->items()->create([
                'product_id' => $originalItem->product_id,
                'product_name' => $originalItem->product_name,
                'quantity' => -$qty, // Negative for returns
                'unit_price' => $originalItem->unit_price,
                'total_price' => -$itemTotal,
                'tax_amount' => -$itemTax,
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
        
        return 'R' . $date . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    protected static function boot()
    {
        parent::boot();
        
        // Don't auto-generate receipt on create - it's generated when order is PAID
        // static::creating handled separately

        static::updated(function ($order) {
            // Process loyalty when order is marked as paid
            if ($order->status === 'paid' && $order->wasChanged('status') && !$order->is_return) {
                // Loyalty is now processed by the controller
            }
        });
    }
}
