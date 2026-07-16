<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * Immutable resolution context (ADR-004 §10–11).
 *
 * Carries every dimension the deterministic assignment resolver may match
 * against. Null dimensions are wildcards.
 */
final class ResolutionContext
{
    public function __construct(
        public readonly string $templateTypeCode,
        public readonly ?InteractionType $interactionType = null,
        public readonly ?string $entityId = null,
        public readonly ?string $branchId = null,
        public readonly ?string $language = null,
        public readonly ?string $currency = null,
        public readonly ?string $country = null,
        public readonly ?string $jurisdiction = null,
        public readonly ?string $customerGroup = null,
        public readonly ?TemplateChannel $channel = null,
        public readonly ?\DateTimeImmutable $at = null,
    ) {}

    public static function forDocument(string $documentType, array $context = []): self
    {
        return new self(
            templateTypeCode: $documentType,
            interactionType: isset($context['interaction_type'])
                ? InteractionType::from($context['interaction_type'])
                : InteractionType::BusinessDocument,
            entityId: $context['entity_id'] ?? null,
            branchId: $context['branch_id'] ?? $context['branch'] ?? null,
            language: $context['language'] ?? $context['locale'] ?? null,
            currency: $context['currency'] ?? null,
            country: $context['country'] ?? null,
            jurisdiction: $context['jurisdiction'] ?? null,
            customerGroup: $context['customer_group'] ?? null,
            channel: isset($context['channel']) ? TemplateChannel::from($context['channel']) : null,
            at: isset($context['at']) ? new \DateTimeImmutable($context['at']) : null,
        );
    }
}
