<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Illuminate\Support\Str;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Services\PublicTokenService;
use Tests\TenantTestCase;

class PublicTokenServiceTest extends TenantTestCase
{
    private PublicTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->tokens = app(PublicTokenService::class);
    }

    public function test_issue_and_verify_round_trip(): void
    {
        $issued = $this->tokens->issue(
            templateId: null,
            interactionType: InteractionType::SurveyAssessment,
            capability: 'respond',
            expiresAt: now()->addDay(),
        );

        $this->assertNotEmpty($issued['token']);
        $this->assertNotEmpty($issued['record']->id);

        // Token hash is stored, raw token is not.
        $this->assertStringStartsWith('utp_', $issued['token']);
        $this->assertDatabaseMissing('template_public_tokens', ['token_hash' => $issued['token']]);

        $verified = $this->tokens->verify($issued['token']);

        $this->assertSame($issued['record']->id, $verified->id);
        $this->assertSame(1, $verified->use_count);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $issued = $this->tokens->issue(null, InteractionType::SurveyAssessment);
        $this->tokens->revoke($issued['record']->id, 'no longer needed');

        $this->expectException(TemplatePlatformException::class);

        $this->tokens->verify($issued['token']);
    }

    public function test_expired_token_is_rejected(): void
    {
        $issued = $this->tokens->issue(
            null,
            InteractionType::DataEntryForm,
            expiresAt: now()->subMinute(),
        );

        $this->expectException(TemplatePlatformException::class);

        $this->tokens->verify($issued['token']);
    }

    public function test_max_uses_exhaustion_is_rejected(): void
    {
        $issued = $this->tokens->issue(null, InteractionType::DataEntryForm, maxUses: 1);
        $this->tokens->verify($issued['token']);

        $this->expectException(TemplatePlatformException::class);

        $this->tokens->verify($issued['token']);
    }

    public function test_single_use_nonce_blocks_replay(): void
    {
        $issued = $this->tokens->issue(null, InteractionType::SurveyAssessment);
        $nonce = 'nonce-'.Str::uuid();

        $this->tokens->verify($issued['token'], $nonce);

        // Replay the same nonce -> rejected.
        $this->expectException(TemplatePlatformException::class);

        $this->tokens->verify($issued['token'], $nonce);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->expectException(TemplatePlatformException::class);

        $this->tokens->verify('utp_bogus_token');
    }
}
