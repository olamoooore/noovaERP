<?php

namespace Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    use HasFactory;

    protected $table = 'crm_activities';

    protected $fillable = [
        'lead_id',
        'type',
        'subject',
        'description',
        'activity_date',
        'duration_minutes',
        'user_id',
        'outcome',
    ];

    protected $casts = [
        'activity_date' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    public const TYPES = ['call', 'email', 'meeting', 'task', 'note'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
