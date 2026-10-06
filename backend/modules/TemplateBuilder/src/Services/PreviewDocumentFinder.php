<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #620 (TPL-UX3): recent real documents a designer can preview a
 * template draft against ("Preview with INV-2026-0412").
 *
 * Only documents the caller may render are offered: the caller must hold the
 * source type's own permission (checked by the controller) and every
 * candidate is re-verified through DocumentRenderAuthorizer — the same entity
 * check a real render uses — so the picker never lists a document the
 * preview would then refuse.
 */
final class PreviewDocumentFinder
{
    /**
     * Document type → [model, number column, date column]. Types that share a
     * source record (the sales order family) list the same model.
     *
     * @var array<string, array{0: class-string<\Illuminate\Database\Eloquent\Model>, 1: string|null, 2: string|null}>
     */
    private const SOURCES = [
        'invoice' => [\App\Models\Invoice::class, 'invoice_number', 'invoice_date'],
        'quotation' => [\App\Models\Quotation::class, 'quotation_number', 'quotation_date'],
        'purchase_order' => [\Modules\Inventory\Models\PurchaseOrder::class, 'po_number', 'order_date'],
        'receipt' => [\App\Models\Payment::class, 'payment_number', 'payment_date'],
        'delivery_order' => [\Modules\Sales\Models\DeliveryOrder::class, 'delivery_order_number', 'delivery_date'],
        'customer_statement' => [\App\Models\CustomerStatement::class, null, 'end_date'],
        'sales_order' => [\Modules\Sales\Models\SalesOrder::class, 'sales_order_number', 'order_date'],
        'sales_order_confirmation' => [\Modules\Sales\Models\SalesOrder::class, 'sales_order_number', 'order_date'],
        'sales_proforma_invoice' => [\Modules\Sales\Models\SalesOrder::class, 'sales_order_number', 'order_date'],
        'credit_note' => [\App\Models\CustomerCreditNote::class, 'credit_note_number', 'credit_note_date'],
    ];

    /** How many recent rows are inspected to fill the picker. */
    private const CANDIDATES = 40;

    public function __construct(private readonly DocumentRenderAuthorizer $authorizer) {}

    public function supports(string $documentType): bool
    {
        return isset(self::SOURCES[$documentType]);
    }

    /**
     * @return list<array{id: string, number: string, date: string|null, label: string}>
     */
    public function recent(string $documentType, Request $request, int $limit = 15): array
    {
        if (! $this->supports($documentType)) {
            return [];
        }

        [$modelClass, $numberColumn, $dateColumn] = self::SOURCES[$documentType];
        $model = new $modelClass;
        $table = $model->getTable();

        $query = $modelClass::query()->orderByDesc($model->getKeyName());

        // Narrow by the request's entity where the table carries the link
        // directly; rows without it (legacy, customer-derived entity) are kept
        // and decided by the authoritative per-document check below.
        $entityId = $request->attributes->get('legal_entity_id');
        if ($entityId !== null && Schema::hasColumn($table, 'legal_entity_id')) {
            $query->where(fn ($q) => $q->where('legal_entity_id', (int) $entityId)->orWhereNull('legal_entity_id'));
        }

        $documents = [];
        foreach ($query->limit(self::CANDIDATES)->get() as $row) {
            $id = (string) $row->getKey();

            if (! $this->authorizer->visibleInRequestEntity($documentType, $id, $request)) {
                continue;
            }

            $number = $numberColumn !== null ? (string) ($row->getAttribute($numberColumn) ?? '') : '';
            $number = $number !== '' ? $number : '#'.$id;
            $date = $dateColumn !== null ? $row->getAttribute($dateColumn) : null;
            $dateText = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date !== null ? (string) $date : null);

            $documents[] = [
                'id' => $id,
                'number' => $number,
                'date' => $dateText,
                'label' => $dateText ? $number.' · '.$dateText : $number,
            ];

            if (count($documents) >= $limit) {
                break;
            }
        }

        return $documents;
    }
}
