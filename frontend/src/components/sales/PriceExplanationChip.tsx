import { cn } from '@/lib/utils'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAuthStore } from '@/store/authStore'
import { useSalesPricelistDisplayMode } from '@/hooks/useSalesPricelistDisplayMode'
import { explainLinePrice, priceChipLabel, type PriceExplanationInput } from '@/lib/priceExplanation'

/**
 * Issue #342 (SALES-DIFF D5): the per-line price chip. Clicking (or Enter /
 * Space on the focused chip) opens a popover explaining the price from the line's stored
 * pricing_trace — list, tier, FX rate, manual override reason.
 *
 * Cost and margin only appear for roles holding `pricelists.cost` (#338).
 */
export type PriceExplanationChipProps = Omit<PriceExplanationInput, 'canSeeCost'> & {
  className?: string
}

const CHIP_TONE: Record<string, string> = {
  manual: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  default: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
}

export function PriceExplanationChip({ className, ...input }: PriceExplanationChipProps) {
  const canSeeCost = useAuthStore((state) => state.hasPermission('pricelists.cost'))
  const label = priceChipLabel(input)
  const items = explainLinePrice({ ...input, canSeeCost })

  if (!label || items.length === 0) return null

  const isManual = items[0]?.kind === 'manual'

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={`How this price was set: ${label}`}
          className={cn(
            'max-w-full cursor-help truncate rounded px-1.5 py-0.5 text-[10px] font-medium',
            isManual ? CHIP_TONE.manual : CHIP_TONE.default,
            className,
          )}
        >
          {label}
        </button>
      </PopoverTrigger>
      <PopoverContent side="top" className="w-72 p-3 text-xs">
        <p className="mb-1.5 font-medium">How this price was set</p>
        <ul className="space-y-1" aria-label="Price explanation">
          {items.map((item, index) => (
            <li key={index} data-kind={item.kind}>{item.text}</li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  )
}

/**
 * Detail-page variant: explains a saved document line from its stored trace
 * and the document's FX snapshot. Margin is only present on the line when
 * the server already decided the role may see it (#338).
 */
export function DocumentLinePriceChip({
  line,
  document,
  money,
  className,
}: {
  line: Record<string, any>
  document: Record<string, any> | null | undefined
  money: (value: number) => string
  className?: string
}) {
  const displayMode = useSalesPricelistDisplayMode()
  if ((line.line_type ?? 'product') !== 'product' || !line.pricing_trace) return null
  const margin = line.margin_amount != null
    ? { amount: Number(line.margin_amount), percent: line.margin_percent != null ? Number(line.margin_percent) : null }
    : null
  return (
    <PriceExplanationChip
      className={className}
      trace={line.pricing_trace}
      listPrice={line.list_price != null ? Number(line.list_price) : null}
      unitPrice={line.unit_price != null ? Number(line.unit_price) : null}
      quantity={line.quantity != null ? Number(line.quantity) : null}
      discountAmount={line.discount_amount != null ? Number(line.discount_amount) : null}
      currency={document?.currency_code ?? null}
      baseCurrency={document?.base_currency_code ?? null}
      exchangeRate={document?.exchange_rate != null ? Number(document.exchange_rate) : null}
      displayMode={displayMode}
      margin={margin}
      money={money}
    />
  )
}

/** FX snapshot of the document being edited (from useSalesDocumentPricing). */
export interface EditorPriceContext {
  currency?: string | null
  baseCurrency?: string | null
  exchangeRate?: number | null
  rateDate?: string | null
}

/**
 * Editor variant for a line being edited (quotation / sales order / invoice
 * forms): the live trace from the engine, the pending manual price and its
 * typed override reason.
 */
export function EditorLinePriceChip({
  line,
  context,
  money,
  className,
}: {
  line: {
    line_type?: string
    price_mode?: string | null
    override_reason?: string | null
    pricing_trace?: Record<string, any> | null
    pricing_currency?: string | null
    pricing_base_currency?: string | null
    list_price?: number | null
    unit_price: number | ''
    quantity: number | ''
    discount_amount?: number | ''
  }
  context?: EditorPriceContext | null
  money: (value: number) => string
  className?: string
}) {
  const displayMode = useSalesPricelistDisplayMode()
  if ((line.line_type ?? 'product') !== 'product') return null
  return (
    <PriceExplanationChip
      className={className}
      trace={line.pricing_trace ?? null}
      priceMode={line.price_mode ?? null}
      overrideReason={line.override_reason ?? null}
      listPrice={line.list_price ?? null}
      unitPrice={line.unit_price}
      quantity={line.quantity}
      discountAmount={line.discount_amount ?? null}
      currency={context?.currency || line.pricing_currency || null}
      baseCurrency={context?.baseCurrency || line.pricing_base_currency || null}
      exchangeRate={context?.exchangeRate ?? null}
      rateDate={context?.rateDate ?? null}
      displayMode={displayMode}
      money={money}
    />
  )
}
