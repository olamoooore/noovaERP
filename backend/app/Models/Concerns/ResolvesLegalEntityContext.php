<?php

namespace App\Models\Concerns;

use App\Services\LegalEntityContextResolver;

/**
 * Issue #875 (P0, SA1): stamp the legal entity (and its primary book) on Sales
 * documents at creation, from the sales order, source document, customer or
 * the request's entity context.
 *
 * Required when the tenant has entities: a Sales document that cannot be
 * resolved fails closed (RuntimeException ENTITY_CONTEXT_REQUIRED) rather than
 * persisting an entity-less row that entity-filtered reports and entity-scoped
 * users can never see.
 */
trait ResolvesLegalEntityContext
{
    protected static function bootResolvesLegalEntityContext(): void
    {
        static::creating(function ($model) {
            /** @var LegalEntityContextResolver $resolver */
            $resolver = app(LegalEntityContextResolver::class);

            $entityId = ($model->legal_entity_id ?? null) !== null
                ? (int) $model->legal_entity_id
                : null;

            if (! $entityId) {
                $sourceType = null;
                $sourceId = null;

                if (isset($model->pos_order_id)) {
                    $sourceType = 'PosOrder';
                    $sourceId = (int) $model->pos_order_id;
                } elseif (isset($model->web_order_id)) {
                    $sourceType = 'WebOrder';
                    $sourceId = (int) $model->web_order_id;
                } elseif (isset($model->source_type)) {
                    $sourceType = (string) $model->source_type;
                    $sourceId = isset($model->source_id) ? (int) $model->source_id : null;
                }

                $entityId = $resolver->resolveEntity(
                    explicit: null,
                    salesOrderId: isset($model->sales_order_id) ? (int) $model->sales_order_id : null,
                    invoiceId: isset($model->invoice_id) ? (int) $model->invoice_id : null,
                    customerId: isset($model->customer_id) ? (int) $model->customer_id : null,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    required: true,
                );
            }

            if ($entityId) {
                $model->legal_entity_id = $entityId;

                if (empty($model->entity_book_id)) {
                    $model->entity_book_id = $resolver->resolveBookId($entityId);
                }
            }
        });
    }
}
