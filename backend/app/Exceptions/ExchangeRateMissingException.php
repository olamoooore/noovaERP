<?php

namespace App\Exceptions;

/**
 * No usable exchange rate at or before the required date and no manual
 * future-dated override row exists (MRD pricelist-multicurrency FR-1.3/
 * FR-9.2/AC-3). Extends PricingException so every pricing/FX guard is one
 * catch (mapped to HTTP 422 by the API exception renderer).
 */
class ExchangeRateMissingException extends PricingException {}
