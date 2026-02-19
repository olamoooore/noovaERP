<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'function_call',
        'function_response',
        'tokens_used',
        'cost',
    ];

    protected $casts = [
        'function_call' => 'array',
        'function_response' => 'array',
        'tokens_used' => 'integer',
        'cost' => 'decimal:6',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AIConversation::class, 'conversation_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function hasFunctionCall(): bool
    {
        return !empty($this->function_call);
    }
}
