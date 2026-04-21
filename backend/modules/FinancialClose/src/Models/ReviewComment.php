<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewComment extends Model
{
    use HasUuids;

    protected $table = 'fci_review_comments';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'author_id',
        'body',
    ];

    protected $casts = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }
}