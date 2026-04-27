<?php

namespace Modules\POS\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerDisplayResource extends JsonResource
{
    protected $terminal;
    protected $settings;
    
    public function __construct($resource, $terminal = null, $settings = null)
    {
        parent::__construct($resource);
        $this->terminal = $terminal;
        $this->settings = $settings;
    }
    
    public function toArray($request): array
    {
        return [
            'state' => $this->determineState(),
            'payment_state' => $this->payment_state ?? null,
            
            // Cart items (customer-safe fields only)
            'items' => $this->items->map(fn($item) => [
                'name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_subtotal' => $item->line_subtotal,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
                'image_url' => $item->product?->image_url,
            ]),
            
            // Totals
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            
            // Discount (type and value only, no internal IDs)
            'discount' => $this->discount_amount > 0 ? [
                'type' => $this->discount_type,
                'value' => $this->discount_value,
                'amount' => $this->discount_amount,
            ] : null,
            
            // Customer (first name and tier only, no email/phone)
            'customer' => $this->customer ? [
                'first_name' => $this->getFirstName($this->customer->name),
                'loyalty_tier' => $this->customer->loyalty_tier_name ?? null,
                'loyalty_points' => $this->customer->loyalty_points ?? 0,
            ] : null,
            
            // Payment (when in payment state)
            'payment' => $this->when($this->payment_state, [
                'state' => $this->payment_state,
                'method' => $this->payment_method ?? null,
                'amount_tendered' => $this->amount_tendered ?? null,
                'change_due' => $this->change_due ?? null,
            ]),
            
            // Terminal info
            'terminal_name' => $this->terminal?->name,
            
            // Welcome message
            'message' => $this->settings?->settings['customer_display_message'] ?? 'Welcome!',
            
            // Session metadata
            'session_opened_at' => $this->session?->opened_at,
            'last_updated_at' => $this->updated_at,
        ];
    }
    
    private function determineState(): string
    {
        if ($this->payment_state === 'processing') return 'payment-pending';
        if ($this->payment_state === 'completed') return 'payment-success';
        if ($this->payment_state === 'failed') return 'payment-failed';
        if ($this->customer_id) return 'member-identified';
        if ($this->items->count() > 0) return 'cart-active';
        return 'session-active';
    }
    
    private function getFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? 'Member';
    }
}

