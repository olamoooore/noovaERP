<?php

namespace Modules\POS\Models;

use App\Models\User;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'terminal_id',
        'user_id',
        'session_number',
        'status',
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'difference',
        'opening_notes',
        'closing_notes',
        'opened_at',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'session_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'session_id');
    }

    public function heldOrders(): HasMany
    {
        return $this->hasMany(PosHeldOrder::class, 'session_id');
    }

    public function closures(): HasMany
    {
        return $this->hasMany(PosSessionClosure::class, 'session_id');
    }

    /**
     * Calculate expected balance based on transactions
     */
    public function calculateExpectedBalance(): float
    {
        // Start with opening balance
        $balance = $this->opening_balance;
        
        // Add cash sales — POS paid orders use status 'paid', not 'completed'
        $cashSales = $this->orders()
            ->where('status', 'paid')
            ->whereHas('payments', function ($q) {
                $q->where('method', 'cash');
            })
            ->get()
            ->sum(function ($order) {
                return $order->payments->where('method', 'cash')->sum('amount');
            });
        $balance += $cashSales;
        
        // Add cash movements
        $cashIn = $this->cashMovements()->where('type', 'in')->sum('amount');
        $cashOut = $this->cashMovements()->where('type', 'out')->sum('amount');
        $balance += $cashIn - $cashOut;
        
        return $balance;
    }

    /**
     * Close the session
     */
    public function close(float $closingBalance, string $notes = null): void
    {
        $this->expected_balance = $this->calculateExpectedBalance();
        $this->closing_balance = $closingBalance;
        $this->difference = $closingBalance - $this->expected_balance;
        $this->closing_notes = $notes;
        $this->closed_at = now();
        $this->closed_by = TenantUserHelper::resolveTenantUserId();
        $this->status = 'closed';
        $this->save();
    }

    /**
     * Close the session with per-payment-method reconciliation
     */
    public function closeWithPaymentBreakdown(array $paymentCounts, string $notes = null): void
    {
        $this->expected_balance = $this->calculateExpectedBalance();
        $this->closing_notes = $notes;
        $this->closed_at = now();
        $this->closed_by = TenantUserHelper::resolveTenantUserId();
        $this->status = 'closed';

        $totalCounted = 0;

        foreach ($paymentCounts as $paymentCount) {
            $method = $paymentCount['method'] ?? '';
            $countedAmount = (float) ($paymentCount['counted_amount'] ?? 0);
            $expectedAmount = $this->getExpectedAmountForMethod($method);
            $difference = $countedAmount - $expectedAmount;
            $totalCounted += $countedAmount;

            $this->closures()->create([
                'payment_method' => $method,
                'payment_method_name' => $paymentCount['method_name'] ?? $method,
                'expected_amount' => $expectedAmount,
                'counted_amount' => $countedAmount,
                'difference' => $difference,
            ]);
        }

        $this->closing_balance = $totalCounted;
        $this->difference = $totalCounted - $this->expected_balance;
        $this->save();
    }

    /**
     * Get expected amount for a specific payment method
     */
    protected function getExpectedAmountForMethod(string $method): float
    {
        return $this->orders()
            ->where('status', 'paid')
            ->whereHas('payments', function ($q) use ($method) {
                $q->where('method', $method);
            })
            ->get()
            ->sum(function ($order) use ($method) {
                return $order->payments->where('method', $method)->sum('amount');
            });
    }

    /**
     * Get expected payment breakdown for session close
     */
    public function getExpectedPaymentBreakdown(): array
    {
        $breakdown = [];

        $payments = PosPayment::whereHas('order', function ($q) {
            $q->where('session_id', $this->id)->where('status', 'paid');
        })
        ->selectRaw('method, SUM(amount) as total')
        ->groupBy('method')
        ->get();

        foreach ($payments as $payment) {
            $breakdown[] = [
                'method' => $payment->method,
                'expected_amount' => round((float) $payment->total, 2),
            ];
        }

        return $breakdown;
    }

    /**
     * Get session summary
     */
    public function getSummary(): array
    {
        $orders = $this->orders()->where('status', 'paid')->get();
        
        return [
            'total_orders' => $orders->count(),
            'total_sales' => $orders->sum('total'),
            'total_tax' => $orders->sum('tax_amount'),
            'total_discounts' => $orders->sum('discount_amount'),
            'cash_in' => $this->cashMovements()->where('type', 'in')->sum('amount'),
            'cash_out' => $this->cashMovements()->where('type', 'out')->sum('amount'),
            'payment_methods' => $this->getPaymentMethodBreakdown(),
            'expected_balance' => $this->calculateExpectedBalance(),
        ];
    }

    /**
     * Get payment method breakdown
     */
    protected function getPaymentMethodBreakdown(): array
    {
        return PosPayment::whereHas('order', function ($q) {
            $q->where('session_id', $this->id)->where('status', 'completed');
        })
        ->selectRaw('method, SUM(amount) as total')
        ->groupBy('method')
        ->pluck('total', 'method')
        ->toArray();
    }

    /**
     * Generate session number
     */
    public static function generateSessionNumber(): string
    {
        $date = now()->format('Ymd');
        $last = static::where('session_number', 'like', "S{$date}%")
            ->orderBy('session_number', 'desc')
            ->first();
        
        if ($last && preg_match('/(\d+)$/', $last->session_number, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }
        
        return 'S' . $date . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($session) {
            if (empty($session->session_number)) {
                $session->session_number = static::generateSessionNumber();
            }
            if (empty($session->opened_at)) {
                $session->opened_at = now();
            }
        });
    }
}
