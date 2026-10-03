<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'request_type',
        'subject_type',
        'subject_id',
        'request_number',
        'status',
        'amount',
        'reason',
        'notes',
        'metadata',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the subject model (PosOrder, Invoice, etc.)
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /**
     * User who requested approval
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * User who reviewed the request
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Resolve a stored subject_type to the Eloquent class that may be
     * instantiated for it (issue #651).
     *
     * The value is user-supplied history: POST /approvals accepted any string
     * until this issue, and the POS flow stores the short alias 'PosOrder'
     * rather than an FQCN (no morph map exists for it), so Eloquent's own
     * morph resolution (`new <subject_type>`) both fatals on the alias and
     * would happily instantiate any autoloadable class a bad row names —
     * the arbitrary-class-instantiation problem chatter fixed in issue #75.
     *
     * Only values whitelisted in config/approvals.php resolve; anything else
     * returns null and the subject simply loads as null.
     */
    public static function subjectClass(string $subjectType): ?string
    {
        $class = config('approvals.subject_types')[$subjectType] ?? null;

        if (! is_string($class) || $class === '') {
            return null;
        }

        // Defence in depth, mirroring ChatterController::validateSubject():
        // the class must exist and be an Eloquent model, never a
        // framework/vendor class.
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        foreach (['Illuminate\\', 'Symfony\\', 'Composer\\'] as $forbiddenPrefix) {
            if (str_starts_with($class, $forbiddenPrefix)) {
                return null;
            }
        }

        return $class;
    }

    /**
     * Attach the polymorphic `subject` to each request without handing a
     * stored subject_type to the morph loader (issue #651).
     *
     * Replaces the `->with(['subject'])` eager load on list/show/my-requests:
     * that loader does `new <subject_type>`, which fatals with a 500 on the
     * POS alias 'PosOrder' (class does not exist) and would instantiate
     * arbitrary classes for rows created before subject_type was whitelisted.
     * Subjects are resolved per type through the whitelist instead, in one
     * query per distinct subject class.
     *
     * @param  iterable<int, ApprovalRequest>  $requests
     */
    public static function loadSubjects(iterable $requests): void
    {
        $rows = $requests instanceof Collection ? $requests->values() : collect($requests);

        if ($rows->isEmpty()) {
            return;
        }

        /** @var array<class-string, array<int, ApprovalRequest>> $grouped */
        $grouped = [];

        foreach ($rows as $request) {
            $class = static::subjectClass((string) $request->subject_type);

            if ($class === null) {
                $request->setRelation('subject', null);

                continue;
            }

            $grouped[$class][] = $request;
        }

        foreach ($grouped as $class => $group) {
            $keyName = (new $class)->getKeyName();
            $ids = array_values(array_unique(array_map(
                fn (self $request) => $request->subject_id,
                $group,
            )));

            $models = $class::query()
                ->whereIn($keyName, $ids)
                ->get()
                ->keyBy($keyName);

            foreach ($group as $request) {
                $request->setRelation('subject', $models->get($request->subject_id));
            }
        }
    }

    /**
     * Generate unique request number.
     *
     * Takes the max of the well-formed numeric suffixes for today and adds one.
     * Malformed historical values (e.g. "…9.2233720368548E+18" left behind by a
     * float-overflow in the old suffix parse) are ignored — previously they
     * sorted to the top, the regex matched their trailing digits, and every new
     * request collided with the same existing number.
     */
    public static function generateRequestNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = 'APR'.$date;

        $max = static::where('request_number', 'like', $prefix.'%')
            ->pluck('request_number')
            ->reduce(function (int $carry, $value) use ($prefix) {
                $suffix = substr((string) $value, strlen($prefix));

                // Only N.NNNNNN-style numeric suffixes count; anything else is
                // corrupt history and must not break the sequence.
                if (! ctype_digit($suffix) || $suffix === '' || strlen($suffix) > 6) {
                    return $carry;
                }

                return max($carry, (int) $suffix);
            }, 0);

        $number = $max + 1;

        return $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if request is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if request is approved
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if request is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if request is expired
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired' ||
               ($this->expires_at && $this->expires_at->isPast());
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->request_number)) {
                $request->request_number = static::generateRequestNumber();
            }
            if (empty($request->requested_at)) {
                $request->requested_at = now();
            }
            // Default expiry: 24 hours
            if (empty($request->expires_at)) {
                $request->expires_at = now()->addHours(24);
            }
        });
    }
}
