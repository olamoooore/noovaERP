<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;

class MediaResolverService
{
    /**
     * Resolve a product image URL with fallback safety.
     * Never returns broken or internal-only paths to the display client.
     */
    public function resolveProductImage(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            return $raw;
        }

        // Relative path — build absolute URL
        $appUrl = rtrim(config('app.url', ''), '/');
        $path = '/storage/' . ltrim($raw, '/');
        return $appUrl . $path;
    }

    /**
     * Return the screensaver playlist media for a terminal.
     * Placeholder — extend when media playlist module exists.
     */
    public function screensaverPlaylist(?int $playlistId): array
    {
        return [];
    }
}
