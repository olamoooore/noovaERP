import { useCallback, useRef, useState } from 'react'
import type React from 'react'
import pricingApi, { type PricingQuote } from '@/lib/pricingApi'
import { getApiErrorMessage } from '@/lib/utils'

/**
 * Issue #314: one pricing-resolution hook for every sales document editor.
 *
 * The race-safe resolver (#296) and the 3-line copy that existed in
 * QuotationCreatePage, QuotationEditPage and CreateInvoicePage are replaced
 * by this hook so every pricing fix (multi-currency resolution, stale-response
 * guarding, visible errors) lands once and applies to all document types.
 */

export interface SalesPricingMeta {
  base_currency_code?: string
  exchange_rate?: number
  rate_date?: string | null
  rate_source?: string | null
  rate_stale?: boolean
  price_list_name?: string | null
  price_list_currency?: string | null
}

export interface SalesPricingLine {
  line_type?: 'product' | 'note' | 'section' | string
  product_id: string
  quantity: number | '' | null | undefined
  price_mode?: 'auto' | 'manual' | 'carried'
}

export interface SalesPricingOverrides {
  /** Price-list selection that has not landed in state yet ('' = auto). */
  listOverride?: string
  /** Currency selection that has not landed in state yet ('' = auto). */
  currencyOverride?: string
  /** Date selection that has not landed in state yet. */
  dateOverride?: string
  /** Customer that has not landed in state yet. */
  customerIdOverride?: string | number | null
}

export interface UseSalesDocumentPricingOptions<L extends SalesPricingLine> {
  customerId?: string | number | null
  documentDate?: string
  setLines: React.Dispatch<React.SetStateAction<L[]>>
  /**
   * Extra line fields to copy from the engine quote. The hook always applies
   * unit_price + price_mode='auto'; the invoice additionally keeps the full
   * pricing trace for its hover explanation.
   */
  applyQuote?: (line: L, quote: PricingQuote) => Partial<L>
}

export function useSalesDocumentPricing<L extends SalesPricingLine>({
  customerId,
  documentDate,
  setLines,
  applyQuote,
}: UseSalesDocumentPricingOptions<L>) {
  const [currencyCode, setCurrencyCode] = useState('')
  // Explicit price-list selection (FR-2.3 step 1); '' = auto (customer's list).
  const [priceListId, setPriceListId] = useState('')
  const [pricingMeta, setPricingMeta] = useState<SalesPricingMeta | null>(null)
  // FR-1.3/FR-5.2: a resolution failure (missing rate, unsellable currency,
  // rule error) must be VISIBLE, not silently leave stale prices on screen.
  const [pricingError, setPricingError] = useState<string | null>(null)
  // #296: monotonically increasing id of the newest async price resolution —
  // a response may only apply if it is still the latest request.
  const pricingRequestSeqRef = useRef(0)

  const resolvePricingForLines = useCallback(
    async (nextLines: L[], overrides: SalesPricingOverrides = {}) => {
      const productLines = nextLines
        .map((line, index) => ({ line, index }))
        .filter(({ line }) => (line.line_type ?? 'product') === 'product' && !!line.product_id)
      if (productLines.length === 0) return

      // #296: stamp the request before awaiting. A stale response must never
      // apply after a newer resolution has been started.
      const requestId = ++pricingRequestSeqRef.current
      const effectiveList = overrides.listOverride !== undefined ? overrides.listOverride : priceListId
      const effectiveCurrency = overrides.currencyOverride !== undefined ? overrides.currencyOverride : currencyCode
      const effectiveCustomer = overrides.customerIdOverride !== undefined ? overrides.customerIdOverride : customerId

      try {
        const result = await pricingApi.resolveBatch(
          {
            customer_id: effectiveCustomer ? parseInt(String(effectiveCustomer), 10) : null,
            date: (overrides.dateOverride ?? documentDate) || undefined,
            // The editor's currency wins; empty lets the server resolve from
            // the customer's list (FR-5.2).
            document_currency: effectiveCurrency || undefined,
            price_list_id: effectiveList !== '' && effectiveList != null ? Number(effectiveList) : undefined,
          },
          productLines.map(({ line }) => ({
            product_id: parseInt(String(line.product_id), 10),
            quantity: line.quantity === '' || line.quantity == null ? 1 : Number(line.quantity) || 1,
          })),
        )

        // A newer resolve was started while this one was in flight — drop it.
        if (requestId !== pricingRequestSeqRef.current) return

        setCurrencyCode(result.currency_code)
        setPricingMeta({
          base_currency_code: result.base_currency_code,
          exchange_rate: result.exchange_rate,
          rate_date: result.rate_date,
          rate_source: (result as any).rate_source ?? null,
          rate_stale: Boolean((result as any).rate_stale),
          price_list_name: result.price_list_name,
          price_list_currency: result.price_list_currency,
        })

        // Surface resolution failures instead of silently keeping stale prices.
        setPricingError(null)
        const lineError = (result.lines ?? []).map((quote: any) => quote?.error).find(Boolean) ?? null

        // Apply against the CURRENT lines, not the request snapshot, and only
        // to the line that still is the product/quantity the quote was
        // requested for — edits made while the request was in flight survive.
        setLines((current) => {
          const updated = [...current]
          result.lines.forEach((quote: PricingQuote, quoteIndex: number) => {
            const requested = productLines[quoteIndex]
            const target = requested?.index
            if (target === undefined || (quote as any)?.error) return
            const existing = updated[target]
            if (!existing) return
            if (String(existing.product_id) !== String(requested.line.product_id)) return
            const normalizeQty = (value: number | '' | null | undefined) =>
              value === '' || value == null ? 1 : Number(value) || 1
            if (normalizeQty(existing.quantity) !== normalizeQty(requested.line.quantity)) return
            // Auto-filled prices only override auto/empty lines — a hand-typed
            // price must never be silently replaced (FR-6.3).
            if (existing.price_mode === 'manual') return
            updated[target] = {
              ...existing,
              unit_price: quote.unit_price,
              price_mode: 'auto',
              ...(applyQuote ? applyQuote(existing, quote) : {}),
            }
          })
          return updated
        })
        if (lineError) setPricingError(lineError)
      } catch (error) {
        const message = getApiErrorMessage(error) || 'Pricing could not be resolved.'
        setPricingError(typeof message === 'string' ? message : 'Pricing could not be resolved.')
      }
    },
    [applyQuote, currencyCode, customerId, documentDate, priceListId, setLines],
  )

  return {
    currencyCode,
    setCurrencyCode,
    priceListId,
    setPriceListId,
    pricingMeta,
    pricingError,
    setPricingError,
    resolvePricingForLines,
  }
}
