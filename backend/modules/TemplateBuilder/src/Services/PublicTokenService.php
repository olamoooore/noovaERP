<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\TemplatePublicToken;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Platform\TemplateErrorCode;

/**
 * ADR-000 #169 / #176 — signed public token service.
 *
 * Issues, verifies, revokes and rate-limits signed tokens used to access
 * Data-Entry Forms and Surveys outside an authenticated tenant session.
 * The raw token is returned to the caller exactly once; only its hash is
 * stored. Replay protection is enforced via single-use nonces.
 */
final class PublicTokenService
{
    private const TOKEN_PREFIX = 'utp_'; // universal-template-platform

    /**
     * Issue a signed token bound to a template + capability.
     *
     * @param  array<string,mixed>  $scope
     * @return array{token: string, record: TemplatePublicToken}
     */
    public function issue(
        ?string $templateId,
        InteractionType $interactionType,
        string $capability = 'respond',
        array $scope = [],
        ?\DateTimeInterface $expiresAt = null,
        ?string $issuedToEmail = null,
        ?string $issuedIp = null,
        int $rateLimitPerHour = 60,
        int $maxUses = 0,
    ): array {
        $raw = self::TOKEN_PREFIX.Str::random(48);

        $record = TemplatePublicToken::create([
            'token_hash' => $this->hash($raw),
            'template_id' => $templateId,
            'interaction_type' => $interactionType->value,
            'channel' => 'public_link',
            'scope' => $scope,
            'capability' => $capability,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'rate_limit_per_hour' => $rateLimitPerHour,
            'issued_to_email' => $issuedToEmail,
            'issued_ip' => $issuedIp,
            'created_by' => auth()->id(),
        ]);

        return ['token' => $raw, 'record' => $record];
    }

    /**
     * Verify a presented token. Returns the matching record or throws.
     *
     * @throws TemplatePlatformException when expired, revoked, exhausted, or rate-limited.
     */
    public function verify(string $presentedToken, ?string $nonce = null): TemplatePublicToken
    {
        $record = TemplatePublicToken::where('token_hash', $this->hash($presentedToken))->first();

        if ($record === null) {
            throw TemplatePlatformException::fromCode(TemplateErrorCode::ChannelNotAllowed, 'Invalid public token.');
        }

        if ($record->revoked_at !== null) {
            throw TemplatePlatformException::fromCode(TemplateErrorCode::ChannelNotAllowed, 'Token revoked.', ['token_id' => $record->id]);
        }

        if ($record->expires_at !== null && $record->expires_at->isPast()) {
            throw TemplatePlatformException::fromCode(TemplateErrorCode::ChannelNotAllowed, 'Token expired.', ['token_id' => $record->id]);
        }

        if ($record->max_uses > 0 && $record->use_count >= $record->max_uses) {
            throw TemplatePlatformException::fromCode(TemplateErrorCode::ChannelNotAllowed, 'Token use limit reached.', ['token_id' => $record->id]);
        }

        $this->guardRateLimit($record);

        if ($nonce !== null) {
            $this->consumeNonce($record, $nonce);
        }

        $record->increment('use_count');
        $record->forceFill(['last_used_at' => now()])->save();

        return $record->fresh();
    }

    public function revoke(string $tokenId, string $reason): TemplatePublicToken
    {
        $token = TemplatePublicToken::findOrFail($tokenId);
        $token->update(['revoked_at' => now(), 'revocation_reason' => $reason]);

        return $token->fresh();
    }

    /**
     * Consume a single-use nonce to prevent replay of the same submission.
     */
    private function consumeNonce(TemplatePublicToken $token, string $nonce): void
    {
        try {
            DB::table('template_public_token_nonces')->insert([
                'token_id' => $token->id,
                'nonce' => $nonce,
                'used_at' => now(),
            ]);
        } catch (\Throwable) {
            // Unique constraint violation = replay attempt.
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                'Replay detected: nonce already consumed.',
                ['token_id' => $token->id],
            );
        }
    }

    private function guardRateLimit(TemplatePublicToken $token): void
    {
        if ($token->rate_limit_per_hour <= 0) {
            return;
        }

        $recent = DB::table('template_public_token_nonces')
            ->where('token_id', $token->id)
            ->where('used_at', '>', now()->subHour())
            ->count();

        if ($recent >= $token->rate_limit_per_hour) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ChannelNotAllowed,
                'Rate limit exceeded for public token.',
                ['token_id' => $token->id, 'limit' => $token->rate_limit_per_hour],
            );
        }
    }

    private function hash(string $raw): string
    {
        // Tokens are high-entropy (48 random chars); a deterministic SHA-256 is the
        // standard lookup-hash pattern (cf. Laravel Sanctum's plain-text-token hash).
        return hash('sha256', $raw);
    }

    public function check(string $presentedToken): bool
    {
        return TemplatePublicToken::where('token_hash', $this->hash($presentedToken))->whereNull('revoked_at')->exists();
    }
}
