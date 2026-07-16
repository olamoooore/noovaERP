<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * ADR-004 §9 — Editorial lifecycle states.
 *
 * Publication is an editorial state only; runtime activation is a separate
 * eligibility decision (ADR-004 §2 non-negotiable separation).
 */
enum EditorialState: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Superseded = 'superseded';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In Review',
            self::Published => 'Published',
            self::Superseded => 'Superseded',
            self::Archived => 'Archived',
        };
    }

    /** States eligible for runtime resolution (ADR-004 §9). */
    public function isResolvable(): bool
    {
        return $this === self::Published;
    }

    /** States in which the definition can be edited (ADR-004 §9). */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Permitted editorial transitions (ADR-004 §9).
     *
     * @return list<self>
     */
    public function permittedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Archived],
            self::InReview => [self::Published, self::Draft, self::Archived],
            self::Published => [self::Draft, self::Superseded, self::Archived],
            self::Superseded => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->permittedTransitions(), true);
    }
}
