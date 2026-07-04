<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactBankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contact_bank_accounts';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'bank_name', 'account_name',
        'account_number', 'account_type', 'routing_number', 'swift_code',
        'iban', 'currency', 'branch_name', 'branch_address',
        'is_primary', 'is_active', 'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
