import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

/**
 * Issue #687 (UX1) / #379: the confirm-time shortage summary.
 *
 * Before an order is confirmed, the document's lines are preflighted through
 * the shared bulk stock check. When some lines cannot be met, this dialog
 * says so explicitly and asks the user to choose:
 *
 *  - Deliver partially — confirm and open the delivery-order page to dispatch
 *    what is on hand now;
 *  - Backorder & confirm — confirm and leave the missing quantity as a
 *    backorder (the historical #379 behaviour, made an explicit choice);
 *  - Change warehouse — do not confirm; the user first commits the order to a
 *    warehouse that can supply it.
 *
 * Nothing is confirmed while this dialog is open — the previous silent
 * backorder path is never taken without one of these choices.
 */

export interface ShortageLine {
  product_id?: number | string
  product_name?: string | null
  requested: number
  available: number
  incoming_quantity?: number
}

const formatQty = (value: number) =>
  Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 })

export function StockShortageDialog({
  open,
  onOpenChange,
  lines,
  pending,
  onBackorder,
  onDeliverPartially,
  onChangeWarehouse,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  lines: ShortageLine[]
  pending?: boolean
  onBackorder: () => void
  onDeliverPartially: () => void
  onChangeWarehouse: () => void
}) {
  const count = lines.length
  const label = `${count} line${count === 1 ? '' : 's'} short`

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-destructive">
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5" />
            {label}
          </DialogTitle>
          <DialogDescription>
            {label} — deliver partially / backorder / change warehouse. The missing quantity will
            not be reserved yet; it stays as a backorder until stock arrives.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-64 overflow-y-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Product</TableHead>
                <TableHead className="text-right">Requested</TableHead>
                <TableHead className="text-right">Available</TableHead>
                <TableHead className="text-right">Incoming</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((line, index) => (
                <TableRow key={`${line.product_id ?? index}`}>
                  <TableCell className="font-medium">
                    {line.product_name || `Product #${line.product_id ?? index + 1}`}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    Requested {formatQty(line.requested)}
                  </TableCell>
                  <TableCell className="text-right tabular-nums text-destructive">
                    Available {formatQty(line.available)}
                  </TableCell>
                  <TableCell className="text-right tabular-nums text-muted-foreground">
                    Incoming {formatQty(Number(line.incoming_quantity ?? 0))}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        <DialogFooter className="flex-col gap-2 sm:flex-row sm:justify-between">
          <Button type="button" variant="outline" onClick={onChangeWarehouse} disabled={pending}>
            Change warehouse
          </Button>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Button type="button" variant="outline" onClick={onDeliverPartially} disabled={pending}>
              Deliver partially
            </Button>
            <Button type="button" onClick={onBackorder} disabled={pending}>
              {pending ? 'Confirming…' : 'Backorder & confirm'}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
