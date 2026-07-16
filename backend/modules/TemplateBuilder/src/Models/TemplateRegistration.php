<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Platform\RuntimeMode;

/**
 * ADR-004 §7 — Universal Template Registry row.
 *
 * Authoritative business-purpose registration that every template instance
 * binds to. Owns the interaction type, runtime mode, data contract,
 * required platform services, entitlement codes, component profile,
 * channels, scope/jurisdiction and security/retention policy.
 */
class TemplateRegistration extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'template_registrations';

    protected $fillable = [
        'id',
        'domain_module_code',
        'template_type_code',
        'interaction_type',
        'runtime_mode',
        'name',
        'description',
        'source_or_target_contract',
        'required_platform_services',
        'entitlement_codes',
        'component_profile',
        'channels',
        'scope_jurisdiction',
        'security_retention',
        'is_system',
        'is_active',
        'acceptance_reference',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'source_or_target_contract' => 'array',
        'required_platform_services' => 'array',
        'entitlement_codes' => 'array',
        'component_profile' => 'array',
        'channels' => 'array',
        'scope_jurisdiction' => 'array',
        'security_retention' => 'array',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $registration) {
            if ($registration->id === null) {
                $registration->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class, 'registration_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDomain($query, string $moduleCode)
    {
        return $query->where('domain_module_code', $moduleCode);
    }

    public function scopeForInteraction($query, InteractionType $type)
    {
        return $query->where('interaction_type', $type->value);
    }

    public function interactionType(): InteractionType
    {
        return InteractionType::from($this->interaction_type);
    }

    public function runtimeMode(): RuntimeMode
    {
        return RuntimeMode::from($this->runtime_mode);
    }

    /** Permitted channels for this registration (ADR-004 §7). */
    public function channels(): array
    {
        return $this->channels ?? ['html', 'pdf'];
    }

    /** Required ADR-000 platform service numbers (ADR-004 §5). */
    public function requiredServices(): array
    {
        return $this->required_platform_services ?? [];
    }
}
