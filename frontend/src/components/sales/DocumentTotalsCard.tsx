import { type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { SALES_CONTROL } from '@/components/sales/salesDesignTokens'
import { FieldSlot } from '@/components/ui/field-errors'

export interface DocumentTotalsCardProps {
  subtotal: number
  tax: number
  total: number
  money: (value: number) => string
  /** Defaults to "Document discount". */
  discountLabel?: string
  /** Interactive discount control when the handlers are provided. */
  discountMode?: 'fixed' | 'percentage'
  onDiscountModeChange?: (mode: 'fixed' | 'percentage') => void
  discountAmount?: number | ''
  onDiscountAmountChange?: (value: number | '') => void
  /** Cap for percentage mode (defaults to 100). */
  discountMax?: number
  /** #318: validation key the discount control reports errors under. */
  discountFieldKey?: string
  lineDiscounts?: number
  documentDiscountAmount?: number
  /** Small print rows under the total (base equivalent, priced-by, ...). */
  footer?: ReactNode
  className?: string
}

/**
 * Canonical document totals summary (invoice / quotation / vendor bill …):
 * put it at the end of every create/edit document form so the money block
 * looks and behaves the same everywhere.
 */
export function DocumentTotalsCard({
  subtotal,
  tax,
  total,
  money,
  discountLabel = 'Document discount',
  discountMode = 'fixed',
  onDiscountModeChange,
  discountAmount = '',
  onDiscountAmountChange,
  discountMax = 100,
  discountFieldKey = 'discount_amount',
  lineDiscounts = 0,
  documentDiscountAmount = 0,
  footer,
  className,
}: DocumentTotalsCardProps) {
  const interactive = typeof onDiscountAmountChange === 'function'
  const discounts = (lineDiscounts || 0) + (documentDiscountAmount || 0)
  const showDiscountRow = interactive || (typeof discountAmount === 'number' && discountAmount !== 0)

  return (
    <div className={cn('mt-6 flex justify-end', className)}>
      <div className="w-full max-w-sm overflow-hidden rounded-xl border bg-muted/20">
        <div className="space-y-2.5 px-4 py-3 text-sm">
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">Subtotal</span>
            <span className="font-medium tabular-nums">{money(subtotal)}</span>
          </div>

          {showDiscountRow && (
            <div className="flex items-center justify-between gap-3">
              <span className="text-muted-foreground">{discountLabel}</span>
              {interactive ? (
                <FieldSlot name={discountFieldKey} className="flex flex-col items-end">
                  <div className="flex items-center gap-1.5">
                    <select
                      aria-label="Document discount mode"
                      value={discountMode}
                      onChange={(event) => onDiscountModeChange?.(event.target.value as 'fixed' | 'percentage')}
                      className={cn(SALES_CONTROL, 'h-9 w-auto pr-2')}
                    >
                      <option value="fixed">Fixed</option>
                      <option value="percentage">%</option>
                    </select>
                    <input
                      type="number"
                      min="0"
                      max={discountMode === 'percentage' ? discountMax : undefined}
                      step="0.01"
                      placeholder="0"
                      aria-label="Document discount amount"
                      data-field-focus
                      value={discountAmount === '' ? '' : discountAmount}
                      onChange={(event) => onDiscountAmountChange?.(
                        event.target.value === '' ? '' : Number(event.target.value),
                      )}
                      className={cn(SALES_CONTROL, 'w-24 text-right tabular-nums')}
                    />
                  </div>
                </FieldSlot>
              ) : (
                <span className="font-medium tabular-nums">
                  {money(typeof discountAmount === 'number' ? discountAmount : 0)}
                </span>
              )}
            </div>
          )}

          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">Tax</span>
            <span className="font-medium tabular-nums">{money(tax)}</span>
          </div>

          {discounts > 0 && (
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Discounts</span>
              <span className="font-medium tabular-nums text-green-600">-{money(discounts)}</span>
            </div>
          )}
        </div>

        <div className="flex items-center justify-between border-t bg-background px-4 py-3">
          <span className="text-sm font-semibold">Total</span>
          <span className="text-xl font-bold tabular-nums text-primary">{money(total)}</span>
        </div>

        {footer && (
          <div className="space-y-1 border-t px-4 py-2 text-xs text-muted-foreground">{footer}</div>
        )}
      </div>
    </div>
  )
}