/**
 * Default tax rate for a document line.
 *
 * The server resolves the product's configured tax into `effective_tax_rate`
 * (product tax_rate → linked tax → tenant default tax). When a product has no
 * resolvable tax, the sales-settings default is the last fallback.
 */
export function resolveLineTaxRate(
  product: unknown,
  tenantDefaultTaxRate?: number | null,
): number | '' {
  const candidate = product as { effective_tax_rate?: unknown; tax_rate?: unknown } | null | undefined

  const productRate = Number(candidate?.effective_tax_rate ?? candidate?.tax_rate ?? 0)
  if (Number.isFinite(productRate) && productRate > 0) {
    return productRate
  }

  const fallback = Number(tenantDefaultTaxRate ?? 0)
  return Number.isFinite(fallback) && fallback > 0 ? fallback : ''
}
