<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChatterMessage extends Model
{
    protected $table = 'chatter_messages';

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'author_id',
        'body',
        'mentions',
    ];

    protected $casts = [
        'mentions' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
