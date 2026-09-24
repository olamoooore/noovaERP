<?php

declare(strict_types=1);

namespace App\Services;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Fpdi/TCPDF with the "Powered by TCPDF" meta-link disabled.
 *
 * TCPDF's Close() unconditionally appends a "Powered by TCPDF (www.tcpdf.org)"
 * link to the bottom of every document when its protected $tcpdflink flag is
 * true, and there is no public setter. This subclass turns it off so signed
 * documents carry only the signature, not a vendor watermark.
 */
class SignatureStampPdf extends Fpdi
{
    public function __construct(
        $orientation = 'P',
        $unit = 'mm',
        $format = 'A4',
        $unicode = true,
        $encoding = 'UTF-8',
        $diskcache = false,
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);
        $this->tcpdflink = false;
    }
}
