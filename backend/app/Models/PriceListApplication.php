<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applicability row for a PriceList (MRD FR-2.2): who may use the list.
 * `customer_group` ids reference customer_categories rows — the existing
 * customer categories act as groups in v1.
 */
class PriceListApplication extends Model
{
    public const APPLIES_ALL = 'all';

    public const APPLIES_GROUP = 'customer_group';

    public const APPLIES_CUSTOMER = 'customer';

    protected $fillable = [
        'price_list_id',
        'applies_to_type',
        'applies_to_id',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'applies_to_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'applies_to_id');
    }
}
