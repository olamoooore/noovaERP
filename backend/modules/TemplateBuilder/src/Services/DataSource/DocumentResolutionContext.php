<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use Modules\TemplateBuilder\Platform\TemplateChannel;

/**
 * Builds the resolution-context array a data source exposes for a document.
 *
 * ADR-004 §11: every populated dimension narrows template assignment
 * matching; empty dimensions are wildcards and are omitted. `channel` is a
 * TemplateChannel (output channel) — a document origin that is not one of
 * the platform's channels (e.g. a Sales channel value) is not a resolvable
 * dimension and is dropped rather than crashing resolution.
 */
final class DocumentResolutionContext
{
    /**
     * @param  array<string,mixed>  $dimensions
     * @return array<string,mixed>
     */
    public static function build(array $dimensions): array
    {
        $context = [];

        foreach ($dimensions as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($key === 'entity_id' || $key === 'branch_id') {
                // ResolutionContext types both dimensions as ?string.
                $value = (string) $value;
            }

            if ($key === 'channel') {
                $channel = TemplateChannel::tryFrom((string) $value);
                if ($channel === null) {
                    continue;
                }

                $value = $channel->value;
            }

            $context[$key] = $value;
        }

        return $context;
    }
}
