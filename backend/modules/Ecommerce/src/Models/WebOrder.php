<?php

namespace Modules\Ecommerce\Models;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebOrder extends Model
{
    use HasFactory;

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

    // Convert WebOrder to Invoice
    public function convertToInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer_id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount + $this->loyalty_discount,
            'total' => $this->total,
            'balance' => 0, // Paid
            'status' => 'paid',
            'notes' => "Web Order: {$this->order_number}",
            'created_by' => \App\Models\User::first()?->id ?? 1,
        ]);

        // Create invoice lines
        foreach ($this->items as $item) {
            $invoice->lines()->create([
                'product_id' => $item->product_id,
                'description' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ]);
        }

        // Update order with invoice reference
        $this->update(['invoice_id' => $invoice->id]);

        // Update redemption with invoice_id if points were redeemed
        // The redemption is linked via the loyalty_transaction which has reference_type='web_order'
        if ($this->loyalty_points_redeemed > 0) {
            // Find the loyalty transaction for this web order redemption
            $loyaltyTransaction = \Modules\Loyalty\Models\LoyaltyTransaction::where('reference_type', 'web_order')
                ->where('reference_id', $this->id)
                ->where('type', 'redeem')
                ->first();
            
            if ($loyaltyTransaction) {
                // Update the redemption record linked to this transaction
                \Modules\Loyalty\Models\LoyaltyRedemption::where('loyalty_transaction_id', $loyaltyTransaction->id)
                    ->update(['invoice_id' => $invoice->id]);
            }
        }

        return $invoice;
    }
}
