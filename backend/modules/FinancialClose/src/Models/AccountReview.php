<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountReview extends Model
{
    use HasUuids;

    protected $table = 'fci_account_reviews';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'book_id',
        'account_code',
        'account_name',
        'status',
        'risk_status',
        'assigned_to',
        'due_date',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'due_date' => 'datetime',
    ];

    public function evidence(): HasMany
    {
        return $this->hasMany(SupportingEvidence::class, 'review_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class, 'review_id');
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(Signoff::class, 'review_id');
    }

    public function rollforwards(): HasMany
    {
        return $this->hasMany(AccountRollforward::class, 'review_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AiFinding::class, 'review_id');
    }
}