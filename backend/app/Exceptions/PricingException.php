<?php

namespace App\Exceptions;

/**
 * Raised when a price cannot be computed deterministically — missing FX rate,
 * cyclic "other pricelist" bases, or malformed rule data. Controllers map it
 * to a 422 with the message (MRD pricelist-multicurrency FR-4.2: "error if
 * none"; AC-3 distinct error).
 */
class PricingException extends \RuntimeException {}
