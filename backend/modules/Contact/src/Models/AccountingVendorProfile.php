<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingVendorProfile extends Model
{
    use HasFactory;

    protected $table = 'accounting_vendor_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'vendor_code', 'payment_terms',
        'credit_limit', 'current_balance', 'currency', 'payment_method',
        'default_bank_account_id', 'account_opened_date', 'account_status',
        'metadata',
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
