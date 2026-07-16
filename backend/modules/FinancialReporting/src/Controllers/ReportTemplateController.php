<?php

namespace Modules\FinancialReporting\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\FinancialReporting\Models\ReportTemplate;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Report Template Controller
 * Manages report templates for customized report configurations
 */
class ReportTemplateController extends Controller
{
    /**
     * Get all report templates
     */
    public function index(Request $request): JsonResponse
    {
        $query = ReportTemplate::query();

        // Filter by report type
        if ($request->has('report_type')) {
            $query->where('report_type', $request->input('report_type'));
        }

        // Filter by system templates
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        // Filter by user's templates
        if ($request->boolean('my_templates')) {
            $query->where('created_by', TenantUserHelper::resolveTenantUserId());
        }

        $templates = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Get a single report template
     */
    public function show(int $id): JsonResponse
    {
        $template = ReportTemplate::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $template,
        ]);
    }

    /**
     * Create a new report template
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'report_type' => 'required|string|in:profit_loss,balance_sheet,cash_flow,trial_balance,general_ledger,stock_valuation,stock_movement,sales_by_product,sales_by_customer,expenses_by_category',
            'configuration' => 'required|array',
            'configuration.columns' => 'nullable|array',
            'configuration.filters' => 'nullable|array',
            'configuration.grouping' => 'nullable|array',
            'configuration.sorting' => 'nullable|array',
            'configuration.formatting' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template = new ReportTemplate;
        $template->name = $request->input('name');
        $template->description = $request->input('description');
        $template->report_type = $request->input('report_type');
        $template->configuration = $request->input('configuration');
        $template->is_system = false;
        $template->created_by = TenantUserHelper::resolveTenantUserId();
        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Report template created successfully',
            'data' => $template,
        ], 201);
    }

    /**
     * Update a report template
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = ReportTemplate::findOrFail($id);

        // Prevent updating system templates
        if ($template->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'System templates cannot be modified',
            ], 403);
        }

        // Check ownership
        if ($template->created_by !== TenantUserHelper::resolveTenantUserId()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update this template',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'report_type' => 'sometimes|required|string|in:profit_loss,balance_sheet,cash_flow,trial_balance,general_ledger,stock_valuation,stock_movement,sales_by_product,sales_by_customer,expenses_by_category',
            'configuration' => 'sometimes|required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('name')) {
            $template->name = $request->input('name');
        }

        if ($request->has('description')) {
            $template->description = $request->input('description');
        }

        if ($request->has('report_type')) {
            $template->report_type = $request->input('report_type');
        }

        if ($request->has('configuration')) {
            $template->configuration = $request->input('configuration');
        }

        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Report template updated successfully',
            'data' => $template,
        ]);
    }

    /**
     * Delete a report template
     */
    public function destroy(int $id): JsonResponse
    {
        $template = ReportTemplate::findOrFail($id);

        // Prevent deleting system templates
        if ($template->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'System templates cannot be deleted',
            ], 403);
        }

        // Check ownership
        if ($template->created_by !== TenantUserHelper::resolveTenantUserId()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this template',
            ], 403);
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Report template deleted successfully',
        ]);
    }

    /**
     * Duplicate a template
     */
    public function duplicate(int $id): JsonResponse
    {
        $original = ReportTemplate::findOrFail($id);

        $template = new ReportTemplate;
        $template->name = $original->name.' (Copy)';
        $template->description = $original->description;
        $template->report_type = $original->report_type;
        $template->configuration = $original->configuration;
        $template->is_system = false;
        $template->created_by = TenantUserHelper::resolveTenantUserId();
        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Template duplicated successfully',
            'data' => $template,
        ], 201);
    }

    /**
     * Share a template (make it public)
     */
    public function share(int $id): JsonResponse
    {
        $template = ReportTemplate::findOrFail($id);

        // Check ownership
        if ($template->created_by !== TenantUserHelper::resolveTenantUserId()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to share this template',
            ], 403);
        }

        // Add shared flag to configuration
        $configuration = $template->configuration;
        $configuration['shared'] = true;
        $configuration['shared_by'] = TenantUserHelper::resolveTenantUserId();
        $configuration['shared_at'] = now()->toIso8601String();
        $template->configuration = $configuration;
        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Template shared successfully',
            'data' => $template,
        ]);
    }

    /**
     * Unshare a template (make it private)
     */
    public function unshare(int $id): JsonResponse
    {
        $template = ReportTemplate::findOrFail($id);

        // Check ownership
        if ($template->created_by !== TenantUserHelper::resolveTenantUserId()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to unshare this template',
            ], 403);
        }

        // Remove shared flag from configuration
        $configuration = $template->configuration;
        unset($configuration['shared']);
        unset($configuration['shared_by']);
        unset($configuration['shared_at']);
        $template->configuration = $configuration;
        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Template unshared successfully',
            'data' => $template,
        ]);
    }

    /**
     * Apply a template to generate a report
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $template = ReportTemplate::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'filters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Merge template configuration with request filters
        $configuration = $template->configuration;
        $filters = $request->input('filters', []);

        // Apply template filters as defaults
        if (isset($configuration['filters'])) {
            $filters = array_merge($configuration['filters'], $filters);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template applied successfully',
            'data' => [
                'template' => $template,
                'report_type' => $template->report_type,
                'configuration' => $configuration,
                'filters' => $filters,
            ],
        ]);
    }
}
