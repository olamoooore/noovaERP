<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

/**
 * Outcome of a {@see FormCommandAdapter::execute()} call.
 */
final class CommandAdapterResult
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $targetRecordType = null,
        public readonly ?string $targetRecordId = null,
        public readonly array $payload = [],
        public readonly ?string $message = null,
    ) {}

    public static function success(string $recordType, string $recordId, array $payload = []): self
    {
        return new self(true, $recordType, $recordId, $payload);
    }

    public static function rejected(string $message, array $payload = []): self
    {
        return new self(false, null, null, $payload, $message);
    }
}
