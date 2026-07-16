<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves tax rates and profiles for a given entity based on its country pack.
 */
class TaxProfileService
{
    /**
     * Get all tax profiles for a country pack.
     */
    public function getForCountry(int $countryPackId): Collection
    {
        return DB::table('tax_profiles')
            ->where('country_pack_id', $countryPackId)
            ->orderBy('tax_type')
            ->orderByDesc('effective_from')
            ->get();
    }

    /**
     * Get the effective tax rate for a given type and date.
     */
    public function getEffectiveRate(int $countryPackId, string $taxType, ?string $date = null): ?float
    {
        $date = $date ?? now()->toDateString();

        $profile = DB::table('tax_profiles')
            ->where('country_pack_id', $countryPackId)
            ->where('tax_type', $taxType)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();

        return $profile ? (float) $profile->rate : null;
    }

    /**
     * Get the default tax profile for a country pack and type.
     */
    public function getDefault(int $countryPackId, string $taxType): ?object
    {
        return DB::table('tax_profiles')
            ->where('country_pack_id', $countryPackId)
            ->where('tax_type', $taxType)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Get all active tax profiles (effective now) for a country pack.
     */
    public function getActiveProfiles(int $countryPackId): Collection
    {
        $today = now()->toDateString();

        return DB::table('tax_profiles')
            ->where('country_pack_id', $countryPackId)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            })
            ->orderBy('tax_type')
            ->get();
    }

    /**
     * Create a new tax profile.
     */
    public function create(array $data): int
    {
        return DB::table('tax_profiles')->insertGetId(array_merge($data, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Seed tax profiles from a country pack's default_tax_rates JSON.
     */
    public function seedFromCountryPack(int $countryPackId): int
    {
        $pack = DB::table('country_packs')->find($countryPackId);
        if (! $pack) {
            throw new \RuntimeException("Country pack {$countryPackId} not found.");
        }

        $defaults = json_decode($pack->default_tax_rates, true) ?? [];
        $count = 0;

        foreach ($defaults as $taxRate) {
            DB::table('tax_profiles')->insert([
                'country_pack_id' => $countryPackId,
                'tax_type' => $taxRate['type'],
                'rate' => $taxRate['rate'],
                'effective_from' => $taxRate['effective_from'],
                'effective_to' => null,
                'is_default' => true,
                'config' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }
}
