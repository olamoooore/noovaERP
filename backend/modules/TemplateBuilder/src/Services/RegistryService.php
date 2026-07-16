<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Collection;
use Modules\TemplateBuilder\Models\TemplateRegistration;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Support\CanonicalRegistrations;

/**
 * ADR-004 §7 — Universal Template Registry service.
 *
 * Manages the authoritative catalogue of registered template types per
 * tenant and seeds the canonical Sales/CRM/Accounting registrations.
 */
final class RegistryService
{
    /**
     * Seed the canonical ADR-004 §4.1 registrations for the current tenant.
     * Idempotent — skips type codes that already exist.
     */
    public function seedCanonical(): int
    {
        $seeded = 0;

        foreach (CanonicalRegistrations::definitions() as $definition) {
            $exists = TemplateRegistration::query()
                ->where('template_type_code', $definition['template_type_code'])
                ->where('interaction_type', $definition['interaction_type'])
                ->exists();

            if ($exists) {
                continue;
            }

            TemplateRegistration::create(array_merge($definition, [
                'is_system' => true,
                'is_active' => true,
            ]));

            $seeded++;
        }

        return $seeded;
    }

    /**
     * @return \Illuminate\Support\Collection<int, TemplateRegistration>
     */
    public function list(?string $domain = null, ?InteractionType $type = null): Collection
    {
        return TemplateRegistration::query()
            ->active()
            ->when($domain, fn ($q) => $q->forDomain($domain))
            ->when($type, fn ($q) => $q->forInteraction($type))
            ->orderBy('domain_module_code')
            ->orderBy('template_type_code')
            ->get();
    }

    public function find(string $typeCode, ?InteractionType $type = null): ?TemplateRegistration
    {
        return TemplateRegistration::query()
            ->where('template_type_code', $typeCode)
            ->when($type, fn ($q) => $q->forInteraction($type))
            ->first();
    }

    public function register(array $data): TemplateRegistration
    {
        return TemplateRegistration::create(array_merge($data, [
            'is_system' => false,
            'is_active' => true,
        ]));
    }

    public function activate(TemplateRegistration $registration): TemplateRegistration
    {
        $registration->update(['is_active' => true]);

        return $registration->fresh();
    }

    public function deactivate(TemplateRegistration $registration): TemplateRegistration
    {
        $registration->update(['is_active' => false]);

        return $registration->fresh();
    }
}
