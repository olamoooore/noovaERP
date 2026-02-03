<?php

namespace Modules\IoT\Services;

use Modules\IoT\Models\DeviceTemplate;
use Modules\IoT\Models\DiscoveredDevice;

class DeviceTemplateMatchingService
{
    /**
     * Find a matching template for a discovered device.
     */
    public function findMatch(DiscoveredDevice $device): ?DeviceTemplate
    {
        // Try exact VID/PID match first
        if ($device->vendor_id && $device->product_id) {
            $template = DeviceTemplate::active()
                ->byVendorProduct($device->vendor_id, $device->product_id)
                ->first();

            if ($template) {
                return $template;
            }
        }

        // Try manufacturer/model match
        if ($device->manufacturer && $device->model) {
            $template = DeviceTemplate::active()
                ->byManufacturerModel($device->manufacturer, $device->model)
                ->first();

            if ($template) {
                return $template;
            }
        }

        // Try fuzzy manufacturer match (case-insensitive partial match)
        if ($device->manufacturer) {
            $template = DeviceTemplate::active()
                ->where('manufacturer', 'ILIKE', "%{$device->manufacturer}%")
                ->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Find all possible matches for a discovered device.
     */
    public function findAllMatches(DiscoveredDevice $device): array
    {
        $matches = [];

        // Exact VID/PID match
        if ($device->vendor_id && $device->product_id) {
            $exactMatch = DeviceTemplate::active()
                ->byVendorProduct($device->vendor_id, $device->product_id)
                ->first();

            if ($exactMatch) {
                $matches[] = [
                    'template' => $exactMatch,
                    'confidence' => 100,
                    'match_type' => 'exact_vid_pid',
                ];
            }
        }

        // Manufacturer/model match
        if ($device->manufacturer && $device->model) {
            $manufacturerMatch = DeviceTemplate::active()
                ->byManufacturerModel($device->manufacturer, $device->model)
                ->first();

            if ($manufacturerMatch && !in_array($manufacturerMatch->id, array_column($matches, 'id'))) {
                $matches[] = [
                    'template' => $manufacturerMatch,
                    'confidence' => 90,
                    'match_type' => 'manufacturer_model',
                ];
            }
        }

        // Fuzzy manufacturer match
        if ($device->manufacturer) {
            $fuzzyMatches = DeviceTemplate::active()
                ->where('manufacturer', 'ILIKE', "%{$device->manufacturer}%")
                ->get();

            foreach ($fuzzyMatches as $fuzzyMatch) {
                if (!in_array($fuzzyMatch->id, array_column($matches, 'id'))) {
                    $matches[] = [
                        'template' => $fuzzyMatch,
                        'confidence' => 70,
                        'match_type' => 'fuzzy_manufacturer',
                    ];
                }
            }
        }

        // Sort by confidence
        usort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $matches;
    }

    /**
     * Generate suggested configuration from template.
     */
    public function generateConfiguration(
        DeviceTemplate $template,
        DiscoveredDevice $device,
        array $overrides = []
    ): array {
        $config = $template->getDeviceConfiguration([
            'name' => $overrides['name'] ?? "{$template->name} - {$device->port}",
            'connection_info' => array_merge([
                'vendor_id' => $device->vendor_id,
                'product_id' => $device->product_id,
                'port' => $device->port,
                'printer_name' => $overrides['printer_name'] ?? null,
            ], $overrides['connection_info'] ?? []),
            'metadata' => array_merge([
                'discovered_device_id' => $device->id,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serial_number' => $device->serial_number,
            ], $overrides['metadata'] ?? []),
        ]);

        return $config;
    }
}
