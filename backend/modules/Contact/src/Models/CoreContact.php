<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoreContact extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'core_contacts';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'tenant_id',
        'entity_id',
        'contact_type',
        'first_name',
        'last_name',
        'company_name',
        'display_name',
        'email',
        'phone',
        'mobile',
        'website',
        'notes',
        'source',
        'legacy_id',
        'is_active',
        'is_duplicate',
        'merged_into_id',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_duplicate' => 'boolean',
        'metadata' => 'array',
    ];

    // --- Relationships ---

    public function roles(): HasMany
    {
        return $this->hasMany(ContactRole::class, 'contact_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ContactIdentifier::class, 'contact_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class, 'contact_id');
    }

    public function methods(): HasMany
    {
        return $this->hasMany(ContactMethod::class, 'contact_id');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ContactRelationship::class, 'contact_id');
    }

    public function visibility(): HasMany
    {
        return $this->hasMany(ContactVisibility::class, 'contact_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ContactGroupMember::class, 'contact_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ContactRegistration::class, 'contact_id');
    }

    public function taxProfiles(): HasMany
    {
        return $this->hasMany(ContactTaxProfile::class, 'contact_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(ContactBankAccount::class, 'contact_id');
    }

    public function relatedRecords(): HasMany
    {
        return $this->hasMany(ContactRelatedRecord::class, 'contact_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    // --- Module Profiles ---

    public function accountingCustomerProfile()
    {
        return $this->hasOne(AccountingCustomerProfile::class, 'contact_id');
    }

    public function accountingVendorProfile()
    {
        return $this->hasOne(AccountingVendorProfile::class, 'contact_id');
    }

    public function crmProfile()
    {
        return $this->hasOne(CrmContactProfile::class, 'contact_id');
    }

    public function hrProfile()
    {
        return $this->hasOne(HrStaffProfile::class, 'contact_id');
    }

    public function posLoyaltyProfile()
    {
        return $this->hasOne(PosLoyaltyProfile::class, 'contact_id');
    }

    public function propertyProfile()
    {
        return $this->hasOne(PropertyContactProfile::class, 'contact_id');
    }

    public function projectProfile()
    {
        return $this->hasOne(ProjectContactProfile::class, 'contact_id');
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForEntity($query, $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('contact_type', $type);
    }

    // --- Helpers ---

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name) ?: $this->company_name ?: $this->display_name;
    }

    public function getInitialsAttribute(): string
    {
        $first = strtoupper(substr($this->first_name ?? '', 0, 1));
        $last = strtoupper(substr($this->last_name ?? '', 0, 1));
        return $first . $last ?: strtoupper(substr($this->display_name, 0, 2));
    }
}
