<?php

namespace Modules\AI\Models;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'customer_id',
        'channel',
        'external_id',
        'status',
        'assigned_to',
        'language',
        'context',
        'started_at',
        'closed_at',
        'active_module', // Added for sub-module support
    ];

    protected $casts = [
        'context' => 'array',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AIMessage::class, 'conversation_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function close(): void
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    public function transferToHuman(int $userId): void
    {
        $this->update([
            'status' => 'transferred',
            'assigned_to' => $userId,
        ]);
    }
}
