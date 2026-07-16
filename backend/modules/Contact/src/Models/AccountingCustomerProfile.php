<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingCustomerProfile extends Model
{
    use HasFactory;

    protected $table = 'accounting_customer_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'customer_code', 'payment_terms',
        'credit_limit', 'current_balance', 'currency', 'price_list',
        'discount_group', 'payment_method', 'default_bank_account_id',
        'account_opened_date', 'account_status', 'metadata',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'account_opened_date' => 'date',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
