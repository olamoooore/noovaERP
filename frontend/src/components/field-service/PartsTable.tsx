import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'
import { fieldServiceApi, type WorkOrderPart } from '@/lib/fieldServiceApi'

interface Props {
  workOrderId: number
  parts: WorkOrderPart[]
  readOnly?: boolean
}

const emptyRow = { description: '', quantity: 1, unit_price: 0, is_billable: true }

export function PartsTable({ workOrderId, parts, readOnly = false }: Props) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [newRow, setNewRow] = useState(emptyRow)
  const [addingRow, setAddingRow] = useState(false)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['work-order', workOrderId] })

  const addPart = useMutation({
    mutationFn: (data: typeof newRow) => fieldServiceApi.createPart(workOrderId, data),
    onSuccess: () => { setNewRow(emptyRow); setAddingRow(false); invalidate() },
    onError: () => toast({ title: 'Failed to add part', variant: 'destructive' }),
  })

  const deletePart = useMutation({
    mutationFn: (id: number) => fieldServiceApi.deletePart(id),
    onSuccess: invalidate,
    onError: () => toast({ title: 'Failed to delete part', variant: 'destructive' }),
  })

  const handleAdd = (e: React.FormEvent) => {
    e.preventDefault()
    if (!newRow.description.trim()) return
    addPart.mutate(newRow)
  }

  const totalParts = parts.reduce((s, p) => s + p.line_total, 0)

  return (
    <div className="space-y-3">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Description</TableHead>
            <TableHead className="w-20 text-right">Qty</TableHead>
            <TableHead className="w-28 text-right">Unit Price</TableHead>
            <TableHead className="w-28 text-right">Total</TableHead>
            <TableHead className="w-20 text-center">Billable</TableHead>
            {!readOnly && <TableHead className="w-10" />}
          </TableRow>
        </TableHeader>
        <TableBody>
          {parts.map(part => (
            <TableRow key={part.id}>
              <TableCell className="text-sm">{part.description}</TableCell>
              <TableCell className="text-right text-sm">{part.quantity}</TableCell>
              <TableCell className="text-right text-sm">{Number(part.unit_price).toFixed(2)}</TableCell>
              <TableCell className="text-right text-sm font-medium">{Number(part.line_total).toFixed(2)}</TableCell>
              <TableCell className="text-center">
                <span className={`text-xs ${part.is_billable ? 'text-green-600' : 'text-muted-foreground'}`}>
                  {part.is_billable ? 'Yes' : 'No'}
                </span>
              </TableCell>
              {!readOnly && (
                <TableCell>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 text-destructive hover:text-destructive"
                    onClick={() => deletePart.mutate(part.id)}
                    disabled={deletePart.isPending}
                  >
                    <Icon icon="solar:trash-bin-trash-bold-duotone" width={14} />
                  </Button>
                </TableCell>
              )}
            </TableRow>
          ))}

          {/* New row form */}
          {!readOnly && addingRow && (
            <TableRow>
              <TableCell>
                <Input
                  placeholder="Part description"
                  value={newRow.description}
                  onChange={e => setNewRow(r => ({ ...r, description: e.target.value }))}
                  className="h-8 text-sm"
                  autoFocus
                />
              </TableCell>
              <TableCell>
                <Input
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={newRow.quantity}
                  onChange={e => setNewRow(r => ({ ...r, quantity: parseFloat(e.target.value) || 1 }))}
                  className="h-8 text-sm text-right w-20"
                />
              </TableCell>
              <TableCell>
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={newRow.unit_price}
                  onChange={e => setNewRow(r => ({ ...r, unit_price: parseFloat(e.target.value) || 0 }))}
                  className="h-8 text-sm text-right"
                />
              </TableCell>
              <TableCell className="text-right text-sm font-medium">
                {(newRow.quantity * newRow.unit_price).toFixed(2)}
              </TableCell>
              <TableCell className="text-center">
                <Switch
                  checked={newRow.is_billable}
                  onCheckedChange={v => setNewRow(r => ({ ...r, is_billable: v }))}
                />
              </TableCell>
              <TableCell>
                <Button
                  size="icon"
                  variant="ghost"
                  className="h-7 w-7 text-green-600"
                  onClick={handleAdd}
                  disabled={addPart.isPending}
                >
                  <Icon icon="solar:check-circle-bold-duotone" width={16} />
                </Button>
              </TableCell>
            </TableRow>
          )}

          {parts.length === 0 && !addingRow && (
            <TableRow>
              <TableCell colSpan={readOnly ? 5 : 6} className="text-center text-sm text-muted-foreground py-4">
                No parts added.
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>

      <div className="flex items-center justify-between">
        {!readOnly && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setAddingRow(a => !a)}
          >
            <Icon icon="solar:add-circle-bold-duotone" width={16} className="mr-1" />
            Add Part
          </Button>
        )}
        <div className="ml-auto text-sm font-semibold">
          Total Parts: {totalParts.toFixed(2)}
        </div>
      </div>
    </div>
  )
}
