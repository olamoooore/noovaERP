<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnboardingStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingSessionsController extends Controller
{
    private OnboardingStateMachine $machine;

    public function __construct(OnboardingStateMachine $machine)
    {
        $this->machine = $machine;
    }

    /**
     * List all onboarding sessions for the current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant('id');

        $sessions = \Illuminate\Support\Facades\DB::table('onboarding_sessions')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $sessions]);
    }

    /**
     * Show a single session with step states.
     */
    public function show(int $id): JsonResponse
    {
        $state = $this->machine->getSession($id);
        return response()->json(['data' => $state]);
    }

    /**
     * Create a new onboarding session.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => 'sometimes|in:guided,expert',
        ]);

        $tenantId = tenant('id');
        $mode = $request->input('mode', 'guided');

        $session = $this->machine->createSession(
            $tenantId,
            $mode,
            (string) $request->user()->id
        );

        return response()->json(['data' => $session], 201);
    }

    /**
     * Advance to the next step.
     */
    public function advance(Request $request, int $id): JsonResponse
    {
        $stepData = $request->input('step_data');
        $state = $this->machine->advance($id, $stepData);
        return response()->json(['data' => $state]);
    }

    /**
     * Move back to the previous step (e.g. Back from Review & Validate).
     */
    public function back(int $id): JsonResponse
    {
        $state = $this->machine->back($id);
        return response()->json(['data' => $state]);
    }

    /**
     * Resume a paused/in-progress session.
     */
    public function resume(int $id): JsonResponse
    {
        $state = $this->machine->resume($id);
        return response()->json(['data' => $state]);
    }

    /**
     * Abandon an in-progress session.
     */
    public function abandon(int $id): JsonResponse
    {
        $this->machine->abandon($id);
        return response()->json(['message' => 'Session abandoned.']);
    }

    /**
     * Skip a step (expert mode only).
     */
    public function skip(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'step_code' => 'required|string',
        ]);

        $state = $this->machine->skipStep($id, $request->input('step_code'));
        return response()->json(['data' => $state]);
    }

    /**
     * Return the step definitions (for frontend wizard rendering).
     */
    public function steps(): JsonResponse
    {
        return response()->json(['data' => $this->machine->steps()]);
    }
}
