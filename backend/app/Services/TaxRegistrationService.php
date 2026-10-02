<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TaxRegistration;
use Illuminate\Validation\ValidationException;

/**
 * #748 TU2: the one place a tax registration is written.
 *
 * Both the registration API and the onboarding wizard's tax step go through
 * here, so the pack-driven filing-frequency rule and the "registrations define
 * the home jurisdiction" rule (#740) cannot drift between the two entry points.
 */
class TaxRegistrationService
{
    public function __construct(private readonly TaxPackRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(array $data): TaxRegistration
    {
        $data['jurisdiction'] = strtoupper(trim((string) ($data['jurisdiction'] ?? '')));

        if (! empty($data['regime'])) {
            $data['regime'] = strtoupper(trim((string) $data['regime']));
        }

        $shapes = $this->registry->periodShapes($data['jurisdiction']);
        $allowed = $shapes !== [] ? $shapes : ['monthly', 'quarterly', 'bimonthly'];

        $frequency = strtolower(trim((string) ($data['filing_frequency'] ?? '')));

        if ($frequency !== '') {
            if (! in_array($frequency, $allowed, true)) {
                throw ValidationException::withMessages([
                    'filing_frequency' => "The {$data['jurisdiction']} pack supports: ".implode(', ', $allowed).'.',
                ]);
            }

            $data['filing_frequency'] = $frequency;
        } else {
            // Default to the pack's primary shape so period generation always
            // has a frequency (#750).
            $data['filing_frequency'] = $allowed[0];
        }

        $attributes = array_intersect_key($data, array_flip([
            'legal_entity_id', 'jurisdiction', 'regime', 'tax_profile_id',
            'registration_number', 'effective_from', 'effective_to', 'status',
            'filing_start_month', 'filing_frequency', 'notes',
        ]));

        $registration = TaxRegistration::create($attributes);

        // #740 T4 (AC): registrations — never pack activation — define the
        // tenant's home jurisdiction. The registration just created wins.
        $taxSetting = Setting::getValue('tax', []);
        $taxSetting['jurisdiction'] = $registration->jurisdiction;
        Setting::setValue('tax', $taxSetting);

        return $registration;
    }
}
