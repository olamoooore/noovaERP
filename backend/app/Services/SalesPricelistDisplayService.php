<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Issue #553 (SALES-ODOO): pricelist display mode for sales documents.
 *
 * `show_discount` (default) keeps the list unit price with the line discount
 * broken out; `net_price` presents the unit price already net of the line
 * discount and zeroes the discount field. This is a presentation transform
 * applied to the document line payloads used for PDF/HTML rendering — the
 * stored line figures are never modified.
 */
class SalesPricelistDisplayService
{
    public const MODE_SHOW_DISCOUNT = 'show_discount';

    public const MODE_NET_PRICE = 'net_price';

    public const SETTING_KEY = 'sales.pricelist.display';

    /**
     * Sales document types whose rendered lines carry the pricelist display.
     * Purchase orders and payment receipts are deliberately not affected.
     *
     * @var string[]
     */
    private const SALES_DOCUMENT_TYPES = [
        'invoice',
        'quotation',
        'sales_order',
        'sales_order_confirmation',
        'sales_proforma_invoice',
        'credit_note',
    ];

    public function displayMode(): string
    {
        $mode = Setting::getValue(self::SETTING_KEY, self::MODE_SHOW_DISCOUNT);

        return in_array($mode, [self::MODE_SHOW_DISCOUNT, self::MODE_NET_PRICE], true)
            ? (string) $mode
            : self::MODE_SHOW_DISCOUNT;
    }

    /**
     * Apply the tenant's display mode to a document data-source payload.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function applyToDocumentPayload(string $documentType, array $payload): array
    {
        if (! in_array($documentType, self::SALES_DOCUMENT_TYPES, true)) {
            return $payload;
        }

        if ($this->displayMode() !== self::MODE_NET_PRICE) {
            return $payload;
        }

        if (! isset($payload['lines']) || ! is_array($payload['lines'])) {
            return $payload;
        }

        foreach ($payload['lines'] as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = (float) ($line['quantity'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);

            if ($quantity <= 0 || $discount <= 0 || ! isset($line['unit_price']) || ! is_numeric($line['unit_price'])) {
                continue;
            }

            $payload['lines'][$index]['unit_price'] = round((float) $line['unit_price'] - ($discount / $quantity), 4);
            $payload['lines'][$index]['discount'] = 0.0;
            $payload['lines'][$index]['price_display'] = self::MODE_NET_PRICE;
        }

        return $payload;
    }
}
