<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\FieldService\Models\WorksheetTemplate;

class WorksheetTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = WorksheetTemplate::orderBy('name')->get();

        return response()->json($templates);
    }

    public function show(WorksheetTemplate $template): JsonResponse
    {
        return response()->json($template);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'is_default'  => 'boolean',
            'is_active'   => 'boolean',
            'fields'      => 'required|array',
            'fields.*.id'          => 'required|string',
            'fields.*.label'       => 'required|string|max:255',
            'fields.*.type'        => 'required|string|in:text,number,checkbox,dropdown,date,photo,signature',
            'fields.*.required'    => 'boolean',
            'fields.*.sort_order'  => 'integer|min:0',
            'fields.*.options'     => 'nullable|array',
            'fields.*.placeholder' => 'nullable|string|max:255',
        ]);

        $template = WorksheetTemplate::create($validated);

        if (!empty($validated['is_default'])) {
            $template->setAsDefault();
        }

        return response()->json($template, 201);
    }

    public function update(Request $request, WorksheetTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'is_default'  => 'boolean',
            'is_active'   => 'boolean',
            'fields'      => 'sometimes|array',
            'fields.*.id'          => 'required_with:fields|string',
            'fields.*.label'       => 'required_with:fields|string|max:255',
            'fields.*.type'        => 'required_with:fields|string|in:text,number,checkbox,dropdown,date,photo,signature',
            'fields.*.required'    => 'boolean',
            'fields.*.sort_order'  => 'integer|min:0',
            'fields.*.options'     => 'nullable|array',
            'fields.*.placeholder' => 'nullable|string|max:255',
        ]);

        $template->update($validated);

        if (!empty($validated['is_default'])) {
            $template->setAsDefault();
        }

        return response()->json($template->fresh());
    }

    public function destroy(WorksheetTemplate $template): JsonResponse
    {
        // Prevent deletion if used by any work order types
        if ($template->workOrderTypes()->exists()) {
            return response()->json([
                'message' => 'Cannot delete template: it is assigned to one or more Work Order Types.',
            ], 422);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
