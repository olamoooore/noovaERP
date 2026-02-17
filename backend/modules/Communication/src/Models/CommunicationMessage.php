<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommunicationMessage — The heart of the communication bus.
 *
 * Every communication in the system is stored here: comments, emails,
 * system logs, notifications, chatter messages. Equivalent to Odoo's mail.message.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $model
 * @property int|null $record_id
 * @property string $message_type
 * @property string|null $subtype
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $body_plain
 * @property array|null $metadata
 * @property int|null $author_id
 * @property string $author_type
 * @property string|null $email_from
 * @property string|null $email_to
 * @property string|null $email_cc
 * @property string|null $email_bcc
 * @property string|null $email_message_id
 * @property string|null $email_in_reply_to
 * @property int|null $parent_id
 * @property int|null $template_id
 * @property string $status
 * @property \Carbon\Carbon|null $scheduled_at
 * @property \Carbon\Carbon|null $sent_at
 */
class CommunicationMessage extends Model
{
    use SoftDeletes;

    protected $table = 'communication_messages';

    // Message types
    const TYPE_COMMENT = 'comment';
    const TYPE_EMAIL = 'email';
    const TYPE_NOTIFICATION = 'notification';
    const TYPE_SYSTEM = 'system';
    const TYPE_SMS = 'sms';
    const TYPE_WHATSAPP = 'whatsapp';
    const TYPE_INTERNAL_NOTE = 'internal_note';

    // Statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_QUEUED = 'queued';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'model',
        'record_id',
        'message_type',
        'subtype',
        'subject',
        'body',
        'body_plain',
        'metadata',
        'author_id',
        'author_type',
        'email_from',
        'email_to',
        'email_cc',
        'email_bcc',
        'email_message_id',
        'email_in_reply_to',
        'parent_id',
        'template_id',
        'status',
        'scheduled_at',
        'sent_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────

    public function outboxItems(): HasMany
    {
        return $this->hasMany(CommunicationOutbox::class, 'message_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'template_id');
    }

    public function channelLogs(): HasMany
    {
        return $this->hasMany(CommunicationChannelLog::class, 'message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'author_id');
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeForRecord($query, string $model, int $recordId)
    {
        return $query->where('model', $model)->where('record_id', $recordId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('message_type', $type);
    }

    public function scopeWithSubtype($query, string $subtype)
    {
        return $query->where('subtype', $subtype);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Get the linked business record (polymorphic).
     */
    public function getLinkedRecord(): ?Model
    {
        if (!$this->model || !$this->record_id) {
            return null;
        }

        $modelClass = $this->resolveModelClass($this->model);
        if (!$modelClass || !class_exists($modelClass)) {
            return null;
        }

        return $modelClass::find($this->record_id);
    }

    /**
     * Map short model names to full class paths.
     */
    protected function resolveModelClass(string $model): ?string
    {
        $map = config('communication.model_map', []);
        return $map[$model] ?? null;
    }

    /**
     * Check if this message has been fully delivered on all channels.
     */
    public function isFullyDelivered(): bool
    {
        return $this->outboxItems()
            ->whereNotIn('status', ['delivered', 'sent', 'cancelled'])
            ->doesntExist();
    }
}
