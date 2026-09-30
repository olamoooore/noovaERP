<?php

namespace App\Exceptions;

/**
 * A currency code is unknown, deactivated or not sellable for documents /
 * price lists (MRD pricelist-multicurrency FR-1.1).
 */
class CurrencyNotAvailableException extends PricingException {}
