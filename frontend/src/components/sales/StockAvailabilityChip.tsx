import { useQuery } from '@tanstack/react-query'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import api from '@/lib/api'
import type { StockAvailability } from './stockAvailability'

/**
 * Issue #687 (UX1): compact available-to-promise chip shared by every sales
 * document line editor (invoice lines, standalone delivery orders).
 *
 * The figures come from the shared #465 stock-check path
 * (`GET /sales/delivery-orders/stock-check`) — on hand minus reservations
 * held by other documents, computed once in InventoryService. The chip turns
 * red when the line's requested quantity exceeds what is available and the
 * hover details list incoming supply with expected dates (or disclose that
 * none is scheduled).
 *
 * The chip is hidden while no warehouse is selected: availability is
 * warehouse-scoped, so there is nothing honest to show yet.
 */

const formatStockQty = (value: number) =>
  Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 })

export function StockAvailabilityChip({
  productId,
  warehouseId,
  requested,
  className,
}: {
  productId: number | string | null | undefined
  warehouseId: number | string | null | undefined
  requested: number
  className?: string
}) {
  const enabled = !!productId && !!warehouseId

  const { data, isFetching } = useQuery<StockAvailability>({
    queryKey: ['sales-line-stock-check', String(productId ?? ''), String(warehouseId ?? '')],
    queryFn: async () => {
      const res = await api.get('/sales/delivery-orders/stock-check', {
        params: { product_id: productId, warehouse_id: warehouseId },
      })
      return res.data
    },
    enabled,
    staleTime: 30_000,
  })

  if (!enabled) {
    return null
  }

  if (isFetching && !data) {
    return <span className="text-xs text-muted-foreground">Checking…</span>
  }

  if (!data) {
    return <span className="text-xs text-muted-foreground">—</span>
  }

  const available = Number(data.available)
  const onHand = Number(data.on_hand)
  const reserved = Number(data.reserved)
  const incoming = data.incoming ?? []
  const insufficient = requested > available

  return (
    <TooltipProvider delayDuration={100}>
      <Tooltip>
        <TooltipTrigger asChild>
          <div
            data-testid="stock-availability-chip"
            data-insufficient={insufficient ? 'true' : 'false'}
            tabIndex={0}
            className={cn('cursor-help leading-tight', className)}
          >
            <div
              className={cn(
                'text-xs tabular-nums',
                insufficient ? 'font-semibold text-destructive' : 'font-medium text-foreground',
              )}
            >
              {formatStockQty(available)}
            </div>
            <div className="text-[10px] tabular-nums text-muted-foreground">
              On hand {formatStockQty(onHand)} · Reserved {formatStockQty(reserved)}
            </div>
          </div>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-72 text-xs">
          <div className="space-y-1">
            <div className="font-medium">Available {formatStockQty(available)}</div>
            <div>
              On hand: <span className="tabular-nums">{formatStockQty(onHand)}</span>
            </div>
            <div>
              Reserved: <span className="tabular-nums">{formatStockQty(reserved)}</span>
            </div>
            <div className="pt-1 font-medium">Incoming</div>
            {incoming.length === 0 ? (
              <div className="text-muted-foreground">None scheduled</div>
            ) : (
              <ul className="space-y-0.5">
                {incoming.map((entry, index) => (
                  <li
                    key={`${entry.source_type}-${entry.source_id}-${index}`}
                    className="text-muted-foreground"
                  >
                    {entry.reference || entry.source_type} · {formatStockQty(Number(entry.quantity))}
                    {entry.expected_date ? ` due ${entry.expected_date}` : ' · no date'}
                  </li>
                ))}
              </ul>
            )}
            {insufficient && (
              <div className="pt-1 font-medium text-destructive">
                Requested {formatStockQty(requested)} exceeds available stock
              </div>
            )}
          </div>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
