<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Issue #631 (SALES-CHAT / CH3): canonical naming + checksum for stored
 * evidence files.
 *
 * Evidence uploads used the client's original filename as the storage name
 * (`.../evidence/{getClientOriginalName()}`), so a second upload with the same
 * name silently overwrote the first file while both evidence/chatter rows kept
 * pointing at it. Stored names are now opaque (UUID + sanitised extension) and
 * the original name survives only as row metadata.
 */
final class EvidenceFileStorage
{
    /**
     * A collision-proof storage filename for an uploaded evidence file.
     *
     * The extension comes from the upload's (already allow-listed) mime type,
     * not the client filename, so hostile names like `../../shell.phtml`
     * cannot influence the path or smuggle an executable extension.
     */
    public static function uniqueName(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->guessExtension());
        $extension = substr((string) preg_replace('/[^a-z0-9]/', '', $extension), 0, 16);

        return $extension !== ''
            ? Str::uuid()->toString().'.'.$extension
            : Str::uuid()->toString();
    }

    /** SHA-256 of the uploaded bytes, computed before the file is stored. */
    public static function checksum(UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getRealPath());
    }
}
