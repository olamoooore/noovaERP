<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesChannelReference extends Model
{
    protected $fillable = [
        'sales_order_id',
        'channel',
        'external_id',
        'external_number',
        'raw_document_ids',
    ];

    protected $casts = [
        'raw_document_ids' => 'array',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public static function resolveSalesOrder(string $channel, string $externalId): ?SalesOrder
    {
        $ref = static::where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();

        return $ref?->salesOrder;
    }
}