<?php

namespace Modules\Contact\src\Models;

use App\Platform\DataSecurity\MasksSensitiveFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactBankAccount extends Model
{
    use HasFactory, MasksSensitiveFields, SoftDeletes;

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
        // Issue #117: full bank details are sensitive; encrypt at rest like
        // ContactTaxProfile.tax_id already does in this module.
        'account_number' => 'encrypted',
        'routing_number' => 'encrypted',
        'swift_code' => 'encrypted',
        'iban' => 'encrypted',
    ];

    /**
     * Issue #444 (Decision on #919, as first applied by #567): the account
     * number and IBAN are masked in every JSON rendering unless the viewer
     * holds the seeded contacts.bank_accounts.view permission. Bank name,
     * SWIFT/BIC and branch identify the bank, not the account, and stay
     * readable so a masked account can still be recognised.
     */
    public function sensitiveFields(): array
    {
        return [
            'account_number' => ['permission' => 'contacts.bank_accounts.view', 'mask' => 'default'],
            'iban' => ['permission' => 'contacts.bank_accounts.view', 'mask' => 'default'],
        ];
    }

    public function sensitiveReferenceType(): string
    {
        return 'contact_bank_account';
    }

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
