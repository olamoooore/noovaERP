<?php

/**
 * Website Builder runtime configuration.
 *
 * `max_page_bytes` — hard upper bound on the JSON payload accepted by
 * PUT /api/v1/website-builder/pages/{id}. The frontend now compresses inline
 * images (browser-image-compression) so real pages stay well below this
 * limit, but the guard catches misbehaving clients with a clear 413 message
 * instead of a silent PHP-truncated save.
 */
return [
    'max_page_bytes' => env('WEBSITE_BUILDER_MAX_PAGE_BYTES', 50 * 1024 * 1024),
];
