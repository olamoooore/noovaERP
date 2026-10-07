<?php

namespace App\Models;

use App\Casts\EncryptedOrLegacyString;
use App\Platform\DataSecurity\MasksSensitiveFields;
use App\Platform\Workflow\Contracts\WorkflowSubject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class MedicalCase extends Model implements WorkflowSubject
{
    use HasFactory, MasksSensitiveFields, SoftDeletes;

    protected $fillable = [
        'case_no',
        'case_tag',
        'customer_id',
        // Issue #575 (MC11): the owning legal entity (stamped at creation).
        'legal_entity_id',
        'invoice_id',
        'delivery_address_id',
        'doctor_contact_id',
        'requestor_contact_id',
        'buyer_contact_id',
        'patient_reference',
        'patient_name_encrypted',
        'case_date',
        'procedure_date',
        'location',
        'delivered_to',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'case_date' => 'date',
        'procedure_date' => 'date',
        // Issue #159: PHI must actually be encrypted, not just named so.
        // Issue #223: degrade gracefully on legacy plaintext rows instead of
        // letting a DecryptException 500 every read of the model.
        'patient_name_encrypted' => EncryptedOrLegacyString::class,
    ];

    /**
     * Issue #567 (MC5): the patient name is PHI. It is masked in every JSON
     * rendering unless the viewer holds medical_cases.phi.view (Decision on
     * #919, ADR-007 v0.3 §16A).
     */
    public function sensitiveFields(): array
    {
        return [
            'patient_name_encrypted' => ['permission' => 'medical_cases.phi.view', 'mask' => 'name'],
        ];
    }

    public function sensitiveReferenceType(): string
    {
        return 'medical_case';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->case_no) {
                $model->case_no = static::generateCaseNumber();
            }

            // Issue #575 (MC11): stamp the owning legal entity through the
            // platform resolver (customer → request entity context → the
            // tenant's only entity), so every creation path — controller,
            // CSV/DO import — is entity-scoped.
            if (! $model->legal_entity_id) {
                $model->legal_entity_id = app(\App\Services\LegalEntityContextResolver::class)->resolveEntity(
                    customerId: $model->customer_id ? (int) $model->customer_id : null,
                    required: false,
                );
            }
        });
    }

    public static function generateCaseNumber(): string
    {
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $number = static::generateCaseNumberRaw();
            if (! static::withTrashed()->where('case_no', $number)->exists()) {
                return $number;
            }
            Log::warning('Medical case number collision, retrying', ['attempt' => $attempt]);
        }

        return 'CASE-'.date('Y').'-'.date('is');
    }

    private static function generateCaseNumberRaw(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('medical_case', 'CASE-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            Log::warning('DocumentNumberingService failed for medical case, using fallback');
        }

        $latest = static::withTrashed()
            ->where('case_no', 'like', 'CASE-'.date('Y').'-%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $latest ? (int) substr($latest->case_no, -4) + 1 : 1;

        return 'CASE-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    // Status helpers
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isAllocated(): bool
    {
        return $this->status === 'allocated';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isInvoiced(): bool
    {
        return $this->status === 'invoiced';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'delivery_address_id');
    }

    public function doctorContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'doctor_contact_id');
    }

    public function requestorContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'requestor_contact_id');
    }

    public function buyerContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'buyer_contact_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicalCaseItem::class);
    }

    // Scopes
    /**
     * Issue #159: virtual `patient_name` attribute. The underlying
     * patient_name_encrypted column carries Laravel's `encrypted` cast, so this
     * returns the decrypted value; raw DB reads see ciphertext only.
     */
    public function getPatientNameAttribute(): ?string
    {
        return $this->patient_name_encrypted;
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Issue #575 (MC11): scope to the caller's accessible legal entities
     * (EntityAccessService::accessibleEntityIds). Fail-closed on an empty set.
     * Rows created before the column existed carry NULL and are judged by
     * their customer's entity (Customer::scopeAccessibleBy) — no backfill.
     */
    public function scopeAccessibleBy($query, array $accessibleEntityIds)
    {
        if (empty($accessibleEntityIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($accessibleEntityIds) {
            $q->whereIn('legal_entity_id', $accessibleEntityIds)
                ->orWhere(function ($legacy) use ($accessibleEntityIds) {
                    $legacy->whereNull('legal_entity_id')
                        ->whereHas('customer', fn ($customer) => $customer->accessibleBy($accessibleEntityIds));
                });
        });
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    // ─── Fulfilment rules (#573, MC8) ─────────────────────────────

    /**
     * Issue #573 (MC8): a line is "used" once it carries an allocated or
     * delivered quantity. Unused lines (0 allocated, 0 delivered) do not hold
     * the case open.
     */
    public static function itemIsUsed(MedicalCaseItem $item): bool
    {
        return round((float) $item->allocated_qty, 4) > 0
            || round((float) $item->delivered_qty, 4) > 0;
    }

    /**
     * Issue #573 (MC8): why the case cannot be considered fully invoiced, or
     * null when it is. Every used line must be fully delivered (delivered =
     * allocated) and — unless it is an FOC line redeemed free of charge —
     * fully invoiced (invoiced = delivered). A case with no used line at all
     * has nothing delivered or invoiced, so it is never complete.
     */
    public function fulfilmentBlocker(): ?string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $used = $items->filter(fn (MedicalCaseItem $item) => static::itemIsUsed($item));

        if ($used->isEmpty()) {
            return 'The case has no delivered lines, so it cannot be invoiced or completed.';
        }

        foreach ($used as $item) {
            $allocated = round((float) $item->allocated_qty, 4);
            $delivered = round((float) $item->delivered_qty, 4);
            $invoiced = round((float) $item->invoiced_qty, 4);

            if ($delivered <= 0 || $delivered < $allocated) {
                return "Case line {$item->id} is not fully delivered ({$delivered} of {$allocated}).";
            }

            if (! $item->is_foc && $invoiced < $delivered) {
                return "Case line {$item->id} is not fully invoiced ({$invoiced} of {$delivered} delivered).";
            }
        }

        return null;
    }

    // ─── WorkflowEngine subject (#573 — status writes via the engine) ──

    public function workflowEntityType(): string
    {
        return 'medical_case';
    }

    public function workflowEntityId(): string
    {
        return (string) $this->getKey();
    }

    public function workflowCurrentStatus(): string
    {
        return (string) ($this->status ?? '');
    }

    public function workflowContext(): array
    {
        return [
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'invoice_id' => $this->invoice_id,
        ];
    }

    public function applyWorkflowStatus(string $status, array $context = []): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}
