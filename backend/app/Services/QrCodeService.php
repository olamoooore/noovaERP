<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders QR codes as inline data URIs for emails and documents.
 *
 * Prefers a raster PNG (Imagick) because many email clients refuse SVG
 * images; falls back to a pure-PHP SVG data URI when the Imagick extension
 * is unavailable (e.g. local dev), so callers never need to care.
 */
class QrCodeService
{
    public function dataUri(string $payload, int $size = 220): string
    {
        if (extension_loaded('imagick')) {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle($size, 1),
                new ImagickImageBackEnd
            ));

            return 'data:image/png;base64,'.base64_encode($writer->writeString($payload));
        }

        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($payload));
    }
}
