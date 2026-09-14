<?php

namespace Modules\AI\DTOs;

/**
 * B2-IMPL-01A — Source state binding.
 *
 * Issue #72: explicit binding to the source-owned truth state used by
 * the analysis. Source-state change invokes governed staleness evaluation;
 * source-state change must not automatically mean stale unless the
 * target rule says so.
 */
readonly class SourceStateBinding
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $sourceVersion,
        public string $sourceHash,
        public array $metadata = [],
    ) {}

    /**
     * Compute hash for drift detection.
     */
    public function stateHash(): string
    {
        return \Modules\AI\Models\AnalysisRun::computeSourceStateHash([
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_version' => $this->sourceVersion,
            'source_hash' => $this->sourceHash,
            'metadata' => $this->metadata,
        ]);
    }

    /**
     * Serialize for persistence.
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_version' => $this->sourceVersion,
            'source_hash' => $this->sourceHash,
            'metadata' => $this->metadata,
        ];
    }
}
