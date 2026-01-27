<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\EventSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Exception;

class TenantConfigurationService
{
    protected string $cachePrefix = 'tenant_config:';
    protected int $cacheTtl = 3600; // 1 hour

    /**
     * Get tenant configuration
     */
    public function getTenantConfiguration(string $tenantId): EventSettings
    {
        $this->validateTenantId($tenantId);
        
        $cacheKey = $this->cachePrefix . $tenantId;
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($tenantId) {
            try {
                return EventSettings::getOrCreateForTenant($tenantId);
            } catch (Exception $e) {
                Log::error("Failed to get tenant configuration", [
                    'tenant_id' => $tenantId,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Update tenant configuration
     */
    public function updateTenantConfiguration(string $tenantId, array $settings): EventSettings
    {
        $this->validateTenantId($tenantId);
        $this->validateSettings($settings);
        
        try {
            $eventSettings = EventSettings::getOrCreateForTenant($tenantId);
            $eventSettings->update($settings);
            
            $this->clearConfigurationCache($tenantId);
            
            Log::info("Tenant configuration updated", [
                'tenant_id' => $tenantId,
                'updated_fields' => array_keys($settings)
            ]);
            
            return $eventSettings;
        } catch (Exception $e) {
            Log::error("Failed to update tenant configuration", [
                'tenant_id' => $tenantId,
                'settings' => $settings,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get tenant currency
     */
    public function getTenantCurrency(string $tenantId): string
    {
        return $this->getTenantConfiguration($tenantId)->currency;
    }

    /**
     * Get tenant timezone
     */
    public function getTenantTimezone(string $tenantId): string
    {
        return $this->getTenantConfiguration($tenantId)->timezone;
    }

    /**
     * Check if tenant requires attendee ID
     */
    public function requiresAttendeeId(string $tenantId): bool
    {
        return $this->getTenantConfiguration($tenantId)->requiresAttendeeId();
    }

    /**
     * Get maximum capacity allowed for tenant
     */
    public function getMaxCapacity(string $tenantId): int
    {
        return $this->getTenantConfiguration($tenantId)->getMaxCapacity();
    }

    /**
     * Check if a capacity is allowed for tenant
     */
    public function isCapacityAllowed(string $tenantId, int $capacity): bool
    {
        return $this->getTenantConfiguration($tenantId)->isCapacityAllowed($capacity);
    }

    /**
     * Get tenant cancellation policy
     */
    public function getCancellationPolicy(string $tenantId): ?string
    {
        return $this->getTenantConfiguration($tenantId)->cancellation_policy;
    }

    /**
     * Get tenant refund policy
     */
    public function getRefundPolicy(string $tenantId): ?string
    {
        return $this->getTenantConfiguration($tenantId)->refund_policy;
    }

    /**
     * Get data retention period for tenant
     */
    public function getDataRetentionDays(string $tenantId): int
    {
        return $this->getTenantConfiguration($tenantId)->getDataRetentionDays();
    }

    /**
     * Set tenant currency
     */
    public function setCurrency(string $tenantId, string $currency): EventSettings
    {
        $this->validateCurrency($currency);
        return $this->updateTenantConfiguration($tenantId, ['currency' => $currency]);
    }

    /**
     * Set tenant timezone
     */
    public function setTimezone(string $tenantId, string $timezone): EventSettings
    {
        $this->validateTimezone($timezone);
        return $this->updateTenantConfiguration($tenantId, ['timezone' => $timezone]);
    }

    /**
     * Set cancellation policy
     */
    public function setCancellationPolicy(string $tenantId, string $policy): EventSettings
    {
        return $this->updateTenantConfiguration($tenantId, ['cancellation_policy' => $policy]);
    }

    /**
     * Set refund policy
     */
    public function setRefundPolicy(string $tenantId, string $policy): EventSettings
    {
        return $this->updateTenantConfiguration($tenantId, ['refund_policy' => $policy]);
    }

    /**
     * Set maximum capacity per event
     */
    public function setMaxCapacity(string $tenantId, int $capacity): EventSettings
    {
        if ($capacity <= 0) {
            throw new InvalidArgumentException('Capacity must be greater than 0');
        }
        
        return $this->updateTenantConfiguration($tenantId, ['max_capacity_per_event' => $capacity]);
    }

    /**
     * Set attendee ID requirement
     */
    public function setAttendeeIdRequirement(string $tenantId, bool $required): EventSettings
    {
        return $this->updateTenantConfiguration($tenantId, ['require_attendee_id' => $required]);
    }

    /**
     * Set data retention period
     */
    public function setDataRetentionDays(string $tenantId, int $days): EventSettings
    {
        if ($days <= 0) {
            throw new InvalidArgumentException('Data retention days must be greater than 0');
        }
        
        return $this->updateTenantConfiguration($tenantId, ['data_retention_days' => $days]);
    }

    /**
     * Reset tenant configuration to defaults
     */
    public function resetToDefaults(string $tenantId): EventSettings
    {
        $this->validateTenantId($tenantId);
        
        $defaults = [
            'currency' => config('event_management.defaults.currency', 'USD'),
            'timezone' => config('event_management.defaults.timezone', 'UTC'),
            'max_capacity_per_event' => config('event_management.defaults.max_capacity_per_event', 10000),
            'require_attendee_id' => false,
            'data_retention_days' => config('event_management.defaults.data_retention_days', 2555),
            'cancellation_policy' => null,
            'refund_policy' => null,
        ];
        
        return $this->updateTenantConfiguration($tenantId, $defaults);
    }

    /**
     * Get all tenant configurations (for admin purposes)
     */
    public function getAllTenantConfigurations(): array
    {
        try {
            return EventSettings::all()
                ->keyBy('tenant_id')
                ->toArray();
        } catch (Exception $e) {
            Log::error("Failed to get all tenant configurations", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete tenant configuration
     */
    public function deleteTenantConfiguration(string $tenantId): bool
    {
        $this->validateTenantId($tenantId);
        
        try {
            $eventSettings = EventSettings::forTenant($tenantId);
            
            if (!$eventSettings) {
                return false;
            }
            
            $deleted = $eventSettings->delete();
            $this->clearConfigurationCache($tenantId);
            
            Log::info("Tenant configuration deleted", ['tenant_id' => $tenantId]);
            
            return $deleted;
        } catch (Exception $e) {
            Log::error("Failed to delete tenant configuration", [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate settings array
     */
    protected function validateSettings(array $settings): void
    {
        $validator = Validator::make($settings, [
            'currency' => 'sometimes|string|size:3',
            'timezone' => 'sometimes|string|max:50',
            'cancellation_policy' => 'sometimes|string|nullable',
            'refund_policy' => 'sometimes|string|nullable',
            'max_capacity_per_event' => 'sometimes|integer|min:1',
            'require_attendee_id' => 'sometimes|boolean',
            'data_retention_days' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid settings: ' . $validator->errors()->first());
        }

        // Additional validation for specific fields
        if (isset($settings['currency'])) {
            $this->validateCurrency($settings['currency']);
        }

        if (isset($settings['timezone'])) {
            $this->validateTimezone($settings['timezone']);
        }
    }

    /**
     * Validate currency code
     */
    protected function validateCurrency(string $currency): void
    {
        $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'KRW', 'SGD', 'MYR', 'THB', 'PHP', 'IDR'];
        
        if (!in_array(strtoupper($currency), $validCurrencies)) {
            throw new InvalidArgumentException("Invalid currency code: {$currency}");
        }
    }

    /**
     * Validate timezone
     */
    protected function validateTimezone(string $timezone): void
    {
        if (!in_array($timezone, timezone_identifiers_list())) {
            throw new InvalidArgumentException("Invalid timezone: {$timezone}");
        }
    }

    /**
     * Clear configuration cache for tenant
     */
    protected function clearConfigurationCache(string $tenantId): void
    {
        $cacheKey = $this->cachePrefix . $tenantId;
        Cache::forget($cacheKey);
    }

    /**
     * Validate tenant ID
     */
    protected function validateTenantId(string $tenantId): void
    {
        if (empty(trim($tenantId))) {
            throw new InvalidArgumentException('Tenant ID cannot be empty');
        }
        
        if (strlen($tenantId) > 255) {
            throw new InvalidArgumentException('Tenant ID cannot exceed 255 characters');
        }
    }
}