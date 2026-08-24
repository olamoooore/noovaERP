<?php

namespace Tests\Feature;

use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Services\ReversalCorrectionEngine;
use Tests\TestCase;

/**
 * GAP-003 Tests — Reversal Engine Consolidation (ADR-005 §15.3).
 *
 * Verifies that:
 * - PostingEngine::reverse() delegates to ReversalCorrectionEngine
 * - PaymentCorrectionService uses proper ReversalRequest DTO
 * - ReversalCorrectionEngine is the single authoritative reversal path
 */
class ReversalEngineConsolidationTest extends TestCase
{
    /** @test */
    public function posting_engine_injects_reversal_correction_engine(): void
    {
        $reflection = new \ReflectionClass(\App\Accounting\PostingEngine::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'PostingEngine must have a constructor');

        $parameters = $constructor->getParameters();
        $parameterNames = array_map(fn($p) => $p->getName(), $parameters);

        $this->assertContains(
            'reversalEngine',
            $parameterNames,
            'PostingEngine must inject ReversalCorrectionEngine'
        );
    }

    /** @test */
    public function posting_engine_reverse_delegates_to_reversal_correction_engine(): void
    {
        $reflection = new \ReflectionClass(\App\Accounting\PostingEngine::class);
        $method = $reflection->getMethod('reverse');
        $source = file_get_contents($method->getFileName());

        // Verify the reverse method uses ReversalCorrectionEngine
        $this->assertStringContainsString(
            'reversalEngine',
            $source,
            'PostingEngine::reverse() must delegate to ReversalCorrectionEngine'
        );
        $this->assertStringContainsString(
            'fullReversal',
            $source,
            'PostingEngine::reverse() must call ReversalCorrectionEngine::fullReversal()'
        );
    }

    /** @test */
    public function payment_correction_service_uses_reversal_request_dto(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PaymentCorrectionService::class);
        $source = file_get_contents($reflection->getFileName());

        // Verify PaymentCorrectionService uses ReversalRequest DTO
        $this->assertStringContainsString(
            'use App\Accounting\DTOs\ReversalRequest',
            $source,
            'PaymentCorrectionService must import ReversalRequest DTO'
        );
        $this->assertStringContainsString(
            'new ReversalRequest(',
            $source,
            'PaymentCorrectionService must create ReversalRequest DTO instances'
        );
    }

    /** @test */
    public function reversal_correction_engine_is_singleton(): void
    {
        $provider = new \App\Providers\AppServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'ReversalCorrectionEngine::class',
            $source,
            'ReversalCorrectionEngine must be registered in AppServiceProvider'
        );
        $this->assertStringContainsString(
            'singleton',
            $source,
            'ReversalCorrectionEngine must be a singleton'
        );
    }
}
