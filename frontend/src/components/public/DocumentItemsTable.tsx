import { Icon } from '@iconify/react'
import { resolveImageUrl } from '@/lib/utils'

export interface PublicDocumentLine {
  id: number | string
  line_type?: string | null
  product_name?: string | null
  sku?: string | null
  /** Stored product image path or absolute URL. */
  image?: string | null
  description?: string | null
  model_number?: string | null
  product_notes?: string | null
  patient_name?: string | null
  lot_number?: string | null
  batch_number?: string | null
  serial_number?: string | null
  expiry_date?: string | null
  unit?: string | null
  quantity?: number
  quantity_ordered?: number
  quantity_delivered?: number
  unit_price?: number
  line_total?: number
}

interface DocumentItemsTableProps {
  lines: PublicDocumentLine[]
  currencyCode?: string | null
  /** Header for the quantity column, e.g. "Qty" or "Qty delivered". */
  qtyHeader?: string
  /** Which quantity to show for a line. Defaults to qty delivered, then qty. */
  qtyFor?: (line: PublicDocumentLine) => number
  className?: string
}

const money = (value: number, code?: string | null) => {
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: code && /^[A-Za-z]{3}$/.test(code) ? code : 'USD',
      minimumFractionDigits: 2,
    }).format(value ?? 0)
  } catch {
    return (value ?? 0).toFixed(2)
  }
}

const dateLabel = (iso?: string | null) => {
  if (!iso) return null
  const d = new Date(iso)
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

/**
 * Shared itemised table for the public customer portals (invoice + delivery
 * order). Customers are asked to accept/pay these documents, so the table
 * shows what is actually on them — item name and SKU, model, lot/serial and
 * expiry — rather than a bare description line.
 *
 * Lot/Serial/Expiry columns only appear when at least one line carries them.
 */
export function DocumentItemsTable({
  lines,
  currencyCode,
  qtyHeader = 'Qty',
  qtyFor,
  className = '',
}: DocumentItemsTableProps) {
  const quantityOf = (line: PublicDocumentLine) =>
    qtyFor
      ? qtyFor(line)
      : (line.quantity_delivered ?? line.quantity ?? 0)

  const hasTraceability = lines.some(
    (l) => l.lot_number || l.batch_number || l.serial_number
  )
  const hasExpiry = lines.some((l) => l.expiry_date)
  const hasUnit = lines.some((l) => l.unit)

  const rows = lines.filter((l) => (l.line_type ?? 'product') !== 'section')

  return (
    <div className={`overflow-x-auto ${className}`}>
      <table className="w-full min-w-[560px] border-collapse text-sm">
        <thead>
          <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th className="w-8 py-2 pr-2 font-medium">#</th>
            <th className="py-2 pr-3 font-medium">Item</th>
            {hasTraceability && <th className="py-2 pr-3 font-medium">Lot / Serial</th>}
            {hasExpiry && <th className="py-2 pr-3 font-medium">Expiry</th>}
            <th className="py-2 pr-3 text-right font-medium">{qtyHeader}</th>
            {hasUnit && <th className="py-2 pr-3 font-medium">Unit</th>}
            <th className="py-2 pr-3 text-right font-medium">Unit price</th>
            <th className="py-2 text-right font-medium">Amount</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((line, index) => {
            if ((line.line_type ?? 'product') === 'note') {
              return (
                <tr key={line.id} className="border-b last:border-b-0">
                  <td colSpan={8} className="py-2 pr-2 text-muted-foreground">
                    <span className="flex items-start gap-1.5">
                      <Icon icon="solar:notes-bold-duotone" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      {line.description}
                    </span>
                  </td>
                </tr>
              )
            }

            const title = line.product_name || line.description || '—'

            return (
              <tr key={line.id} className="border-b align-top last:border-b-0">
                <td className="py-3 pr-2 text-muted-foreground">{index + 1}</td>
                <td className="py-3 pr-3">
                  <div className="flex items-start gap-3">
                    {line.image ? (
                      <img
                        src={resolveImageUrl(line.image)}
                        alt={title}
                        loading="lazy"
                        className="h-14 w-14 shrink-0 rounded-md border object-cover"
                      />
                    ) : (
                      <span
                        title="This product has no image"
                        className="flex h-14 w-14 shrink-0 items-center justify-center rounded-md border border-dashed text-muted-foreground"
                      >
                        <Icon icon="solar:gallery-bold-duotone" className="h-5 w-5" />
                      </span>
                    )}
                    <div className="min-w-0">
                      <div className="font-medium">{title}</div>
                      {line.sku && (
                        <div className="text-xs text-muted-foreground">SKU {line.sku}</div>
                      )}
                      {line.product_name && line.description && (
                        <div className="text-xs text-muted-foreground">{line.description}</div>
                      )}
                      <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                        {line.model_number && <span>Model {line.model_number}</span>}
                        {line.patient_name && <span>Patient {line.patient_name}</span>}
                        {line.product_notes && <span>{line.product_notes}</span>}
                      </div>
                    </div>
                  </div>
                </td>
                {hasTraceability && (
                  <td className="py-3 pr-3 text-xs text-muted-foreground">
                    {line.lot_number || line.batch_number || line.serial_number || '—'}
                  </td>
                )}
                {hasExpiry && (
                  <td className="py-3 pr-3 text-xs text-muted-foreground">
                    {dateLabel(line.expiry_date) ?? '—'}
                  </td>
                )}
                <td className="py-3 pr-3 text-right tabular-nums">{quantityOf(line)}</td>
                {hasUnit && <td className="py-3 pr-3 text-xs text-muted-foreground">{line.unit || '—'}</td>}
                <td className="py-3 pr-3 text-right tabular-nums">
                  {money(line.unit_price ?? 0, currencyCode)}
                </td>
                <td className="py-3 text-right font-medium tabular-nums">
                  {money(line.line_total ?? 0, currencyCode)}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default DocumentItemsTable
