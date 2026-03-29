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
     * Ordered step definitions: code → [label, prerequisites].
     * Prerequisites are step_codes that must be 'completed' before this step.
     */
    public const STEPS = [
        'group_setup'       => ['label' => 'Group Setup',                'prerequisites' => []],
        'entity_selection'  => ['label' => 'Entity Selection',           'prerequisites' => ['group_setup']],
        'country_compliance'=> ['label' => 'Country & Compliance',       'prerequisites' => ['entity_selection']],
        'coa_import'        => ['label' => 'Chart of Accounts Import',   'prerequisites' => ['country_compliance']],
        'role_assignment'   => ['label' => 'Account Role Assignment',    'prerequisites' => ['coa_import']],
        'validation'        => ['label' => 'Validation & Review',        'prerequisites' => ['role_assignment']],
        'go_live'           => ['label' => 'Go Live',                    'prerequisites' => ['validation']],
    ];

    /**
     * Create a new onboarding session with all step states initialized.
     */
    public function createSession(string $tenantId, string $mode = 'guided', ?string $createdBy = null): object
    {
        $sessionId = DB::table('onboarding_sessions')->insertGetId([
            'tenant_id'   => $tenantId,
            'mode'        => $mode,
            'current_step'=> array_key_first(self::STEPS),
            'status'      => 'in_progress',
            'started_at'  => now(),
            'created_by'  => $createdBy,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        foreach (self::STEPS as $code => $def) {
            DB::table('onboarding_step_states')->insert([
                'session_id'   => $sessionId,
                'step_code'    => $code,
                'status'       => $code === array_key_first(self::STEPS) ? 'in_progress' : 'pending',
                'data'         => null,
                'completed_at' => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        return DB::table('onboarding_sessions')->find($sessionId);
    }

    /**
     * Get full session state (session + step states).
     */
    public function getSession(int $sessionId): array
    {
        $session = DB::table('onboarding_sessions')->find($sessionId);
        if (!$session) {
            throw new \RuntimeException("Onboarding session {$sessionId} not found.");
        }

        $steps = DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->orderByRaw("CASE step_code " . $this->stepOrderCase() . " END")
            ->get();

        return [
            'session' => $session,
            'steps'   => $steps,
        ];
    }

    /**
     * Advance the session to the next step.
     * Marks the current step as completed and the next step as in_progress.
     */
    public function advance(int $sessionId, ?array $stepData = null): array
    {
        $session = DB::table('onboarding_sessions')->find($sessionId);
        if (!$session || $session->status !== 'in_progress') {
            throw new \RuntimeException("Session {$sessionId} is not in progress.");
        }

        $currentStep = $session->current_step;
        $stepCodes = array_keys(self::STEPS);
        $currentIndex = array_search($currentStep, $stepCodes);

        if ($currentIndex === false) {
            throw new \RuntimeException("Unknown step: {$currentStep}");
        }

        // Validate prerequisites for the NEXT step
        $nextIndex = $currentIndex + 1;

        // Mark current step completed
        DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', $currentStep)
            ->update([
                'status'       => 'completed',
                'data'         => $stepData ? json_encode($stepData) : null,
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

        // If last step, complete session
        if ($nextIndex >= count($stepCodes)) {
            DB::table('onboarding_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'updated_at'   => now(),
                ]);

            return $this->getSession($sessionId);
        }

        $nextStep = $stepCodes[$nextIndex];

        // Verify prerequisites
        $prereqs = self::STEPS[$nextStep]['prerequisites'];
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
                'status'     => 'in_progress',
                'updated_at' => now(),
            ]);

        DB::table('onboarding_sessions')
            ->where('id', $sessionId)
            ->update([
                'current_step' => $nextStep,
                'updated_at'   => now(),
            ]);

        return $this->getSession($sessionId);
    }

    /**
     * Resume an in_progress session (no-op if already active, restores state).
     */
    public function resume(int $sessionId): array
    {
        $session = DB::table('onboarding_sessions')->find($sessionId);
        if (!$session) {
            throw new \RuntimeException("Session {$sessionId} not found.");
        }

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
        DB::table('onboarding_sessions')
            ->where('id', $sessionId)
            ->where('status', 'in_progress')
            ->update([
                'status'     => 'abandoned',
                'updated_at' => now(),
            ]);
    }

    /**
     * Skip a step (only allowed in expert mode).
     */
    public function skipStep(int $sessionId, string $stepCode): array
    {
        $session = DB::table('onboarding_sessions')->find($sessionId);
        if (!$session || $session->mode !== 'expert') {
            throw new \RuntimeException("Step skipping is only available in expert mode.");
        }

        // Cannot skip go_live step
        if ($stepCode === 'go_live') {
            throw new \RuntimeException("Cannot skip the go_live step.");
        }

        DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', $stepCode)
            ->update([
                'status'     => 'skipped',
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
        foreach (array_keys(self::STEPS) as $code) {
            $parts[] = "WHEN '{$code}' THEN {$i}";
            $i++;
        }
        return implode(' ', $parts);
    }
}
