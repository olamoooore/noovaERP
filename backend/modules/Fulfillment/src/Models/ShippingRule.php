<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRule extends Model
{
    use HasUuids;

    protected $table = 'shipping_rules';

    protected $fillable = [
        'tenant_id',
        'name',
        'priority',
        'is_active',
        'conditions',
        'actions',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FulfillmentProvider::class, 'provider_id');
    }

    public function matchesConditions(array $context): bool
    {
        $conditions = $this->conditions ?? [];

        if (isset($conditions['order_total_min']) && $context['order_total'] < $conditions['order_total_min']) {
            return false;
        }

        if (isset($conditions['order_total_max']) && $context['order_total'] > $conditions['order_total_max']) {
            return false;
        }

        if (isset($conditions['weight_min']) && $context['weight'] < $conditions['weight_min']) {
            return false;
        }

        if (isset($conditions['weight_max']) && $context['weight'] > $conditions['weight_max']) {
            return false;
        }

        if (isset($conditions['destination_country'])) {
            $countries = is_array($conditions['destination_country'])
                ? $conditions['destination_country']
                : [$conditions['destination_country']];

            if (! in_array($context['destination_country'], $countries)) {
                return false;
            }
        }

        return true;
    }

    public function getSelectedProvider(): ?array
    {
        return $this->actions['selected_provider'] ?? null;
    }

    public function getSelectedService(): ?string
    {
        return $this->actions['selected_service'] ?? null;
    }
}
