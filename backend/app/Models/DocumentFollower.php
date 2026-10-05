<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Issue #639 (CHATTER-EPIC #628) — a follower of a document.
 *
 * Two kinds of follower live in one table:
 *   - internal: a tenant user (user_id), with subscription flags for
 *     messages / activities / stage changes;
 *   - partner:  a customer contact (contact_id) who receives the customer-
 *     facing messages by email through the Communication engine.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $document_type
 * @property int $document_id
 * @property int|null $user_id
 * @property int|null $contact_id
 * @property string $follower_type
 * @property bool $subscribe_messages
 * @property bool $subscribe_activities
 * @property bool $subscribe_stage_changes
 * @property string $follow_source
 */
class DocumentFollower extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_PARTNER = 'partner';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CONTACT = 'manual:contact';

    public const SOURCE_CREATOR = 'auto:creator';

    public const SOURCE_SALESPERSON = 'auto:salesperson';

    public const SOURCE_APPROVER = 'auto:approver';

    public const SOURCE_STAGE_CHANGE = 'auto:stage_change';

    /** Issue #635: the user was @mentioned in a chatter message. */
    public const SOURCE_MENTION = 'auto:mention';

    protected $table = 'document_followers';

    protected $fillable = [
        'tenant_id',
        'document_type',
        'document_id',
        'user_id',
        'contact_id',
        'follower_type',
        'subscribe_messages',
        'subscribe_activities',
        'subscribe_stage_changes',
        'follow_source',
    ];

    protected $casts = [
        'document_id' => 'integer',
        'user_id' => 'integer',
        'contact_id' => 'integer',
        'subscribe_messages' => 'boolean',
        'subscribe_activities' => 'boolean',
        'subscribe_stage_changes' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'contact_id');
    }

    public function document(): MorphTo
    {
        return $this->morphTo('document', 'document_type', 'document_id');
    }

    public function scopeForDocument(Builder $query, string $documentType, int $documentId): Builder
    {
        return $query->where('document_type', $documentType)->where('document_id', $documentId);
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('follower_type', self::TYPE_INTERNAL);
    }

    public function scopePartner(Builder $query): Builder
    {
        return $query->where('follower_type', self::TYPE_PARTNER);
    }
}
