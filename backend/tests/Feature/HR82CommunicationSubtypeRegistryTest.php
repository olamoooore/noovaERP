<?php

declare(strict_types=1);

namespace Tests\Feature;

use InvalidArgumentException;
use Modules\Communication\Services\CommunicationEngine;
use Modules\Communication\Support\EventSubtypeRegistry;
use Tests\TenantTestCase;

/**
 * Issue #82 — communication subtypes are registry-validated: typos fail loudly
 * at the engine entrance instead of silently skipping delivery, dynamic
 * families (digest.*) and runtime registrations stay supported.
 */
class HR82CommunicationSubtypeRegistryTest extends TenantTestCase
{
    public function test_canonical_and_dynamic_subtypes_are_valid(): void
    {
        foreach ([
            EventSubtypeRegistry::SYSTEM_LOG,
            EventSubtypeRegistry::INTERNAL_NOTE,
            EventSubtypeRegistry::RECORD_COMMENT,
            EventSubtypeRegistry::INVOICE_SENT,
            'digest.weekly',
        ] as $subtype) {
            EventSubtypeRegistry::assertValid($subtype);
            $this->addToAssertionCount(1);
        }
    }

    public function test_runtime_registration_extends_the_registry(): void
    {
        EventSubtypeRegistry::register('custom.module.event');

        EventSubtypeRegistry::assertValid('custom.module.event');
        $this->assertContains('custom.module.event', EventSubtypeRegistry::all());
    }

    public function test_typo_is_rejected_with_a_helpful_message(): void
    {
        try {
            EventSubtypeRegistry::assertValid('invoice_sent');
            $this->fail('A typo subtype must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Unknown communication subtype 'invoice_sent'", $e->getMessage());
            $this->assertStringContainsString('invoice.sent', $e->getMessage(), 'The error should list canonical codes');
        }
    }

    public function test_engine_rejects_an_unknown_subtype_at_the_entrance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CommunicationEngine::class)->logSystem('Invoice', 1, 'should not be written', 'not.a.real.subtype');
    }
}
