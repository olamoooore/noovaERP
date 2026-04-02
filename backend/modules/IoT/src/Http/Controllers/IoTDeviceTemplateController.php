<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Modules\IoT\Models\IoTDeviceTemplate;
use Illuminate\Validation\Rule;

class IoTDeviceTemplateController extends Controller
{
    /**
     * Get all device templates grouped by category
     */
    public function index(Request $request): JsonResponse
    {
        $query = IoTDeviceTemplate::query();

        // Filter by category
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        // Filter by verified status
        if ($request->boolean('verified_only')) {
            $query->verified();
        }

        // Filter by popular status
        if ($request->boolean('popular_only')) {
            $query->popular();
        }

        // Search by name or vendor
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('vendor', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $templates = $query->orderBy('is_popular', 'desc')
                          ->orderBy('is_verified', 'desc')
                          ->orderBy('name')
                          ->get();

        // Group by category if no specific category filter
        if (!$request->has('category')) {
            $grouped = $templates->groupBy('category');
            return response()->json($grouped);
        }

        return response()->json($templates);
    }

    /**
     * Get categories with counts
     */
    public function categories(): JsonResponse
    {
        $categories = IoTDeviceTemplate::selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(function ($item) {
                return [
                    'key' => $item->category,
                    'name' => match($item->category) {
                        'printers' => 'Printers',
                        'scanners' => 'Scanners',
                        'cameras' => 'Cameras',
                        'kiosks' => 'Kiosks',
                        'sensors' => 'Sensors',
                        'displays' => 'Displays',
                        default => ucfirst($item->category),
                    },
                    'count' => $item->count,
                    'icon' => match($item->category) {
                        'printers' => 'solar:printer-bold-duotone',
                        'scanners' => 'solar:qr-code-bold-duotone',
                        'cameras' => 'solar:camera-bold-duotone',
                        'kiosks' => 'solar:monitor-bold-duotone',
                        'sensors' => 'solar:widget-bold-duotone',
                        'displays' => 'solar:tv-bold-duotone',
                        default => 'solar:devices-bold-duotone',
                    },
                ];
            });

        return response()->json($categories);
    }

    /**
     * Get a specific template
     */
    public function show($template): JsonResponse
    {
        $template = IoTDeviceTemplate::findOrFail($template);
        return response()->json($template);
    }

    /**
     * Create a new template
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'type' => 'required|in:printer,scanner,kiosk,camera,agent',
            'vendor' => 'required|string|max:100',
            'model' => 'nullable|string|max:100',
            'connection_type' => 'required|in:cloud,lan,agent,bluetooth,usb',
            'config' => 'required|array',
            'description' => 'nullable|string',
            'image_url' => 'nullable|url',
            'is_verified' => 'boolean',
            'is_popular' => 'boolean',
            'supported_features' => 'nullable|array',
            'setup_instructions' => 'nullable|array',
        ]);

        $template = IoTDeviceTemplate::create($validated);

        return response()->json($template, 201);
    }

    /**
     * Update a template
     */
    public function update(Request $request, $template): JsonResponse
    {
        $template = IoTDeviceTemplate::findOrFail($template);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:50',
            'type' => 'sometimes|in:printer,scanner,kiosk,camera,agent',
            'vendor' => 'sometimes|string|max:100',
            'model' => 'nullable|string|max:100',
            'connection_type' => 'sometimes|in:cloud,lan,agent,bluetooth,usb',
            'config' => 'sometimes|array',
            'description' => 'nullable|string',
            'image_url' => 'nullable|url',
            'is_verified' => 'boolean',
            'is_popular' => 'boolean',
            'supported_features' => 'nullable|array',
            'setup_instructions' => 'nullable|array',
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    /**
     * Delete a template
     */
    public function destroy($template): JsonResponse
    {
        $template = IoTDeviceTemplate::findOrFail($template);
        // Check if template is being used by any devices
        if ($template->devices()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete template that is being used by devices'
            ], 422);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted successfully']);
    }

    /**
     * Create device from template
     */
    public function createDevice(Request $request, $template): JsonResponse
    {
        $template = IoTDeviceTemplate::findOrFail($template);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'config_overrides' => 'nullable|array', // Allow overriding template config
        ]);

        // Merge template config with any overrides
        $config = array_merge($template->config, $validated['config_overrides'] ?? []);

        $device = [
            'name' => $validated['name'],
            'type' => $template->type,
            'vendor' => $template->vendor,
            'connection_type' => $template->connection_type,
            'config' => $config,
            'template_id' => $template->id,
        ];

        // Create the device using the existing IoTDeviceController logic
        $deviceController = new IoTDeviceController();
        $deviceRequest = new Request($device);
        
        return $deviceController->store($deviceRequest);
    }
}