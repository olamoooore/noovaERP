<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\POS\Models\ReceiptTemplate;

class ReceiptTemplateController extends Controller
{
    public function index()
    {
        $templates = ReceiptTemplate::orderBy('updated_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'config' => 'required|array',
            'is_active' => 'boolean',
        ]);

        // If setting as active, deactivate all others first
        if (! empty($validated['is_active'])) {
            ReceiptTemplate::query()->update(['is_active' => false]);
        }

        $template = ReceiptTemplate::create($validated);

        return response()->json([
            'success' => true,
            'data' => $template,
            'message' => 'Template saved successfully',
        ], 201);
    }

    public function show(ReceiptTemplate $receiptTemplate)
    {
        return response()->json([
            'success' => true,
            'data' => $receiptTemplate,
        ]);
    }

    public function update(Request $request, ReceiptTemplate $receiptTemplate)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'config' => 'sometimes|array',
            'is_active' => 'boolean',
        ]);

        if (! empty($validated['is_active'])) {
            ReceiptTemplate::query()->where('id', '!=', $receiptTemplate->id)
                ->update(['is_active' => false]);
        }

        $receiptTemplate->update($validated);

        return response()->json([
            'success' => true,
            'data' => $receiptTemplate->fresh(),
            'message' => 'Template updated successfully',
        ]);
    }

    public function destroy(ReceiptTemplate $receiptTemplate)
    {
        $receiptTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully',
        ]);
    }

    public function activate(ReceiptTemplate $receiptTemplate)
    {
        ReceiptTemplate::query()->update(['is_active' => false]);
        $receiptTemplate->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'data' => $receiptTemplate->fresh(),
            'message' => 'Template activated successfully',
        ]);
    }

    public function active()
    {
        $template = ReceiptTemplate::where('is_active', true)->first();

        return response()->json([
            'success' => true,
            'data' => $template,
        ]);
    }
}
