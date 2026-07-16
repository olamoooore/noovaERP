<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Http\Resources\DeviceTemplateResource;
use Modules\IoT\Models\DeviceTemplate;

class DeviceTemplateController extends Controller
{
    /**
     * Get all device templates.
     *
     * GET /api/v1/iot/device-templates
     */
    public function index(Request $request): JsonResponse
    {
        $query = DeviceTemplate::active();

        // Apply filters
        if ($request->has('device_type')) {
            $query->byType($request->device_type);
        }

        if ($request->has('vendor_id') && $request->has('product_id')) {
            $query->byVendorProduct($request->vendor_id, $request->product_id);
        }

        if ($request->has('manufacturer') && $request->has('model')) {
            $query->byManufacturerModel($request->manufacturer, $request->model);
        }

        $templates = $query->get();

        return response()->json([
            'success' => true,
            'data' => DeviceTemplateResource::collection($templates),
        ]);
    }

    /**
     * Get a specific device template.
     *
     * GET /api/v1/iot/device-templates/{templateId}
     */
    public function show(string $templateId): JsonResponse
    {
        $template = DeviceTemplate::findOrFail($templateId);

        return response()->json([
            'success' => true,
            'data' => new DeviceTemplateResource($template),
        ]);
    }
}
