<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Services\RegistryService;

/**
 * ADR-004 §7 — Universal Template Registry API.
 */
class RegistryController extends Controller
{
    public function __construct(private readonly RegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $domain = $request->input('domain');
        $type = $request->has('interaction_type') ? InteractionType::from($request->input('interaction_type')) : null;

        return response()->json(['data' => $this->registry->list($domain, $type)->values()]);
    }

    public function show(string $typeCode): JsonResponse
    {
        $registration = $this->registry->find($typeCode);
        if (! $registration) {
            return response()->json(['message' => 'Registration not found', 'error_code' => 'TEMPLATE_TYPE_NOT_REGISTERED'], 404);
        }

        return response()->json(['data' => $registration]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRegistration($request);
        $registration = $this->registry->register($data);

        return response()->json(['data' => $registration], 201);
    }

    public function seed(): JsonResponse
    {
        $count = $this->registry->seedCanonical();

        return response()->json(['message' => 'Canonical registrations seeded.', 'seeded' => $count]);
    }

    public function deactivate(string $typeCode): JsonResponse
    {
        $registration = $this->registry->find($typeCode);
        if (! $registration) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        return response()->json(['data' => $this->registry->deactivate($registration)]);
    }

    public function activate(string $typeCode): JsonResponse
    {
        $registration = $this->registry->find($typeCode);
        if (! $registration) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        return response()->json(['data' => $this->registry->activate($registration)]);
    }

    private function validateRegistration(Request $request): array
    {
        return $request->validate([
            'domain_module_code' => 'required|string|max:40',
            'template_type_code' => 'required|string|max:80',
            'interaction_type' => 'required|in:BUSINESS_DOCUMENT,DATA_ENTRY_FORM,SURVEY_ASSESSMENT',
            'runtime_mode' => 'sometimes|in:COUPLED,DECOUPLED,EXTERNAL_INTEGRATION',
            'name' => 'required|string|max:191',
            'description' => 'sometimes|string',
            'required_platform_services' => 'sometimes|array',
            'entitlement_codes' => 'sometimes|array',
            'channels' => 'sometimes|array',
            'component_profile' => 'sometimes|array',
            'scope_jurisdiction' => 'sometimes|array',
            'security_retention' => 'sometimes|array',
            'acceptance_reference' => 'sometimes|string|max:120',
        ]);
    }
}
