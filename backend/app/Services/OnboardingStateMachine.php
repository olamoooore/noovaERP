<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Server-driven onboarding step engine.
 *
 * Step definitions are config-driven. The machine validates transitions,
 * enforces prerequisite ordering, persists step state, and supports save/resume.
 */
class OnboardingStateMachine
{
    /**
     * Get the step definitions from config.
     */
    public function steps(): array
    {
        return config('onboarding.steps', []);
    }

    /**
     * Create a new onboarding session with all step states initialized.
     */
    public function createSession(string $tenantId, string $mode = 'guided', ?string $createdBy = null): object
    {
        $steps = $this->steps();

        $sessionId = DB::table('onboarding_sessions')->insertGetId([
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'current_step' => array_key_first($steps),
            'status' => 'in_progress',
            'started_at' => now(),
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($steps as $code => $def) {
            DB::table('onboarding_step_states')->insert([
                'session_id' => $sessionId,
                'step_code' => $code,
                'status' => $code === array_key_first($steps) ? 'in_progress' : 'pending',
                'data' => null,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('onboarding_sessions')->find($sessionId);
    }

    /**
     * Get full session state (session + step states).
     */
    public function getSession(int $sessionId): array
    {
        $session = $this->sessionForCurrentTenant($sessionId);

        $steps = DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->orderByRaw('CASE step_code '.$this->stepOrderCase().' END')
            ->get();

        return [
            'session' => $session,
            'steps' => $steps,
        ];
    }

    /**
     * Find a session, enforcing tenant isolation.
     *
     * A session that does not exist OR belongs to a different tenant is treated
     * as "not found" so cross-tenant access is blocked and existence is not leaked.
     *
     * @throws \RuntimeException When the session is not found for the current tenant.
     */
    /**
     * #748 TU2: a guided onboarding step that records a tax registration.
     * "Not registered" writes nothing — the UI shows the threshold gauge.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyTaxRegistrationStep(array $data): void
    {
        if (empty($data['registered'])) {
            return;
        }

        $payload = array_intersect_key($data, array_flip([
            'legal_entity_id', 'jurisdiction', 'regime', 'registration_number',
            'effective_from', 'effective_to', 'filing_start_month',
            'filing_frequency', 'notes',
        ]));

        if (empty($payload['jurisdiction']) || empty($payload['registration_number']) || empty($payload['effective_from'])) {
            // Incomplete step data must not crash onboarding; nothing is written.
            return;
        }

        app(\App\Services\TaxRegistrationService::class)->upsert($payload);
    }

    private function sessionForCurrentTenant(int $sessionId): object
    {
        $session = DB::table('onboarding_sessions')->find($sessionId);

        $currentTenant = tenant('id');

        if (! $session || ($currentTenant !== null && $session->tenant_id !== $currentTenant)) {
            throw new \RuntimeException("Onboarding session {$sessionId} not found.");
        }

        return $session;
    }

    /**
     * Advance the session to the next step.
     * Marks the current step as completed and the next step as in_progress.
     */
    public function advance(int $sessionId, ?array $stepData = null): array
    {
        $session = $this->sessionForCurrentTenant($sessionId);
        if ($session->status !== 'in_progress') {
            throw new \RuntimeException("Session {$sessionId} is not in progress.");
        }

        $currentStep = $session->current_step;
        $steps = $this->steps();
        $stepCodes = array_keys($steps);
        $currentIndex = array_search($currentStep, $stepCodes);

        if ($currentIndex === false) {
            throw new \RuntimeException("Unknown step: {$currentStep}");
        }

        // Validate prerequisites for the NEXT step
        $nextIndex = $currentIndex + 1;

        // #748 TU2: the tax-registration step writes the entity's registration
        // (or nothing when the entity is not registered).
        if ($currentStep === 'tax_registration' && is_array($stepData)) {
            $this->applyTaxRegistrationStep($stepData);
        }

        // Mark current step completed
        DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', $currentStep)
            ->update([
                'status' => 'completed',
                'data' => $stepData ? json_encode($stepData) : null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        // If last step, complete session
        if ($nextIndex >= count($stepCodes)) {
            DB::table('onboarding_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            return $this->getSession($sessionId);
        }

        $nextStep = $stepCodes[$nextIndex];

        // Verify prerequisites
        $prereqs = $steps[$nextStep]['prerequisites'];
        foreach ($prereqs as $prereq) {
            $prereqState = DB::table('onboarding_step_states')
                ->where('session_id', $sessionId)
                ->where('step_code', $prereq)
                ->value('status');

            if ($prereqState !== 'completed') {
                throw new \RuntimeException(
                    "Cannot advance to '{$nextStep}': prerequisite '{$prereq}' is not completed."
                );
            }
        }

        // Advance
        DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', $nextStep)
            ->update([
                'status' => 'in_progress',
                'updated_at' => now(),
            ]);

        DB::table('onboarding_sessions')
            ->where('id', $sessionId)
            ->update([
                'current_step' => $nextStep,
                'updated_at' => now(),
            ]);

        return $this->getSession($sessionId);
    }

    /**
     * Move the session back to the previous step.
     *
     * Behaviour:
     *  - The current step is reopened (status set back to 'pending' unless it
     *    still holds saved data, in which case it is kept 'completed' so the
     *    user can amend without losing prior input).
     *  - The previous step becomes the active step ('in_progress').
     *  - Going back from the first step is blocked.
     *  - The session must be 'in_progress'.
     *
     * @return array Session state (session + steps).
     *
     * @throws \RuntimeException When the session cannot move back.
     */
    public function back(int $sessionId): array
    {
        $session = $this->sessionForCurrentTenant($sessionId);

        if ($session->status !== 'in_progress') {
            throw new \RuntimeException("Session {$sessionId} is {$session->status}, cannot go back.");
        }

        $currentStep = $session->current_step;
        $steps = $this->steps();
        $stepCodes = array_keys($steps);
        $currentIndex = array_search($currentStep, $stepCodes, true);

        if ($currentIndex === false) {
            throw new \RuntimeException("Unknown step: {$currentStep}");
        }

        if ($currentIndex === 0) {
            throw new \RuntimeException("Cannot go back from the first step ('{$currentStep}').");
        }

        $previousStep = $stepCodes[$currentIndex - 1];

        // Reopen the previous step so the user can amend it.
        DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', $previousStep)
            ->update([
                'status' => 'in_progress',
                'updated_at' => now(),
            ]);

        // Move the session pointer back to the previous step.
        DB::table('onboarding_sessions')
            ->where('id', $sessionId)
            ->update([
                'current_step' => $previousStep,
                'updated_at' => now(),
            ]);

        return $this->getSession($sessionId);
    }

    /**
     * Resume an in_progress session (no-op if already active, restores state).
     */
    public function resume(int $sessionId): array
    {
        $session = $this->sessionForCurrentTenant($sessionId);

        if ($session->status !== 'in_progress') {
            throw new \RuntimeException("Session {$sessionId} is {$session->status}, cannot resume.");
        }

        return $this->getSession($sessionId);
    }

    /**
     * Abandon an in-progress session.
     */
    public function abandon(int $sessionId): void
    {
        // Enforce tenant ownership before mutating.
        $this->sessionForCurrentTenant($sessionId);

        DB::table('onboarding_sessions')
            ->where('id', $sessionId)
            ->where('status', 'in_progress')
            ->update([
                'status' => 'abandoned',
                'updated_at' => now(),
            ]);
    }

    /**
     * Skip a step (only allowed in expert mode).
     */
    public function skipStep(int $sessionId, string $stepCode): array
    {
        $session = $this->sessionForCurrentTenant($sessionId);
        if ($session->mode !== 'expert') {
            throw new \RuntimeException('Step skipping is only available in expert mode.');
        }

        // Cannot skip go_live step
        if ($stepCode === 'go_live') {
            throw new \RuntimeException('Cannot skip the go_live step.');
        }

        DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', $stepCode)
            ->update([
                'status' => 'skipped',
                'updated_at' => now(),
            ]);

        return $this->getSession($sessionId);
    }

    /**
     * Generate SQL CASE for step ordering.
     */
    private function stepOrderCase(): string
    {
        $parts = [];
        $i = 0;
        foreach (array_keys($this->steps()) as $code) {
            $parts[] = "WHEN '{$code}' THEN {$i}";
            $i++;
        }

        return implode(' ', $parts);
    }
}
