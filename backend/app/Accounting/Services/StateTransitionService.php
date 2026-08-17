<?php

namespace App\Accounting\Services;

use App\Accounting\Enums\ReasonCode;
use App\Accounting\Enums\StateDimension;
use App\Models\StateTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for recording append-only state transitions.
 * ADR-005 §30 — every material transition produces an append-only transition record.
 * Current state is a projection of history; history is not destroyed by current state.
 */
class StateTransitionService
{
    /**
     * Record a state transition.
     *
     * @param string       $aggregateType  e.g. 'Journal', 'Invoice', 'Payment'
     * @param string       $aggregateId    The model ID
     * @param StateDimension $dimension   The lifecycle dimension
     * @param string       $fromState      Previous state
     * @param string       $toState        New state
     * @param array        $context        Additional context (actor, reason, command, etc.)
     */
    public function recordTransition(
        string $aggregateType,
        string $aggregateId,
        StateDimension $dimension,
        string $fromState,
        string $toState,
        array $context = [],
    ): StateTransition {
        return StateTransition::create([
            'tenant_id' => $context['tenant_id'] ?? auth()->user()?->tenant_id ?? 'system',
            'entity_id' => $context['entity_id'] ?? null,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'state_dimension' => $dimension->value,
            'from_state' => $fromState,
            'to_state' => $toState,
            'reason_code' => $context['reason_code'] ?? null,
            'command_id' => $context['command_id'] ?? null,
            'workflow_instance_id' => $context['workflow_instance_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? (string) (auth()->id() ?? 'system'),
            'actor_type' => $context['actor_type'] ?? 'HUMAN',
            'correlation_id' => $context['correlation_id'] ?? null,
            'causation_id' => $context['causation_id'] ?? null,
            'metadata_hash' => isset($context['metadata']) ? hash('sha256', json_encode($context['metadata'])) : null,
            'metadata' => $context['metadata'] ?? null,
        ]);
    }

    /**
     * Get the current state of an aggregate for a given dimension.
     * Current state is the `to_state` of the most recent transition.
     */
    public function getCurrentState(
        string $aggregateType,
        string $aggregateId,
        StateDimension $dimension,
    ): ?string {
        $latest = StateTransition::forAggregate($aggregateType, $aggregateId)
            ->forDimension($dimension->value)
            ->latestFirst()
            ->first();

        return $latest?->to_state;
    }

    /**
     * Get the full transition history for an aggregate.
     */
    public function getHistory(
        string $aggregateType,
        string $aggregateId,
        ?StateDimension $dimension = null,
    ): \Illuminate\Database\Eloquent\Collection {
        $query = StateTransition::forAggregate($aggregateType, $aggregateId)
            ->latestFirst();

        if ($dimension) {
            $query->forDimension($dimension->value);
        }

        return $query->get();
    }

    /**
     * Transition with validation — ensures the from_state matches expected.
     * Returns the transition on success, throws on conflict.
     *
     * @throws \RuntimeException if current state does not match expected from_state
     */
    public function transitionOrThrow(
        string $aggregateType,
        string $aggregateId,
        StateDimension $dimension,
        string $expectedFromState,
        string $toState,
        array $context = [],
    ): StateTransition {
        $currentState = $this->getCurrentState($aggregateType, $aggregateId, $dimension);

        if ($currentState !== $expectedFromState) {
            throw new \RuntimeException(sprintf(
                'State transition rejected: %s [%s] expected from "%s" but current is "%s"',
                $aggregateType,
                $aggregateId,
                $expectedFromState,
                $currentState ?? '(none)',
            ));
        }

        return $this->recordTransition(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            dimension: $dimension,
            fromState: $expectedFromState,
            toState: $toState,
            context: $context,
        );
    }

    /**
     * Check if a transition is valid without performing it.
     */
    public function canTransition(
        string $aggregateType,
        string $aggregateId,
        StateDimension $dimension,
        string $expectedFromState,
    ): bool {
        $currentState = $this->getCurrentState($aggregateType, $aggregateId, $dimension);
        return $currentState === $expectedFromState;
    }
}
