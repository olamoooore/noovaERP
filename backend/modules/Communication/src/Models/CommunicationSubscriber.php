<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommunicationSubscriber — Followers system.
 *
 * Each business record can have subscribers who get notified
 * when messages are created for that record. Equivalent to Odoo's mail.followers.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $model
 * @property int $record_id
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property string|null $email
 * @property array|null $channels
 * @property array|null $subtypes
 * @property bool $is_active
 */
class CommunicationSubscriber extends Model
{
    protected $table = 'communication_subscribers';

    protected $fillable = [
        'tenant_id',
        'model',
        'record_id',
        'user_id',
        'customer_id',
        'email',
        'channels',
        'subtypes',
        'is_active',
    ];

    protected $casts = [
        'channels' => 'array',
        'subtypes' => 'array',
        'is_active' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class, 'customer_id');
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeForRecord($query, string $model, int $recordId)
    {
        return $query->where('model', $model)->where('record_id', $recordId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Check if this subscriber should receive a given subtype.
     */
    public function shouldReceiveSubtype(string $subtype): bool
    {
        // If no subtypes filter set, receive everything
        if (empty($this->subtypes)) {
            return true;
        }

        return in_array($subtype, $this->subtypes);
    }

    /**
     * Get the notification email for this subscriber.
     */
    public function getEmail(): ?string
    {
        if ($this->email) {
            return $this->email;
        }

        if ($this->user) {
            return $this->user->email;
        }

        if ($this->customer) {
            return $this->customer->email;
        }

        return null;
    }

    /**
     * Get preferred channels, falling back to config defaults.
     */
    public function getChannels(): array
    {
        return $this->channels ?? config('communication.default_channels', ['in_app', 'email']);
    }
}
