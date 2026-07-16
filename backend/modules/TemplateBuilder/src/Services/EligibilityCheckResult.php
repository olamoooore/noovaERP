<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Modules\TemplateBuilder\Platform\TemplateErrorCode;

/**
 * Outcome of a single step in the ADR-004 §10 evaluation order.
 */
final class EligibilityCheckResult
{
    public function __construct(
        public readonly int $step,
        public readonly string $name,
        public readonly bool $passed,
        public readonly ?TemplateErrorCode $errorCode = null,
        public readonly ?string $detail = null,
    ) {}

    public static function pass(int $step, string $name): self
    {
        return new self($step, $name, true);
    }

    public static function fail(int $step, string $name, TemplateErrorCode $code, ?string $detail = null): self
    {
        return new self($step, $name, false, $code, $detail);
    }

    public static function delegated(int $step, string $name): self
    {
        // ADR-001 base controls enforced upstream by platform middleware.
        return new self($step, $name, true, detail: 'delegated to ADR-001 platform middleware');
    }
}
