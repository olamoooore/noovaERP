<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactTaxProfile extends Model
{
    use HasFactory;

    protected $table = 'contact_tax_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'tax_profile_type',
        'tax_classification', 'tax_id', 'tax_id_type',
        'withholding_tax_rate', 'is_tax_exempt', 'exemption_reason',
        'exemption_expiry', 'metadata',
    ];

    protected $casts = [
        'withholding_tax_rate' => 'decimal:2',
        'is_tax_exempt' => 'boolean',
        'exemption_expiry' => 'date',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
