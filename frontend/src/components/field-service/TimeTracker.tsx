import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'
import { fieldServiceApi, type TimeLog } from '@/lib/fieldServiceApi'

interface Props {
  workOrderId: number
  timeLogs: TimeLog[]
  readOnly?: boolean
}

const empty = {
  description: '',
  start_time: '',
  end_time: '',
  hourly_rate: 0,
  is_billable: true,
}

function formatDuration(minutes?: number | null) {
  if (!minutes) return '—'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return `${h}h ${m}m`
}

export function TimeTracker({ workOrderId, timeLogs, readOnly = false }: Props) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(empty)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['work-order', workOrderId] })

  const addLog = useMutation({
    mutationFn: (data: typeof form) => fieldServiceApi.createTimeLog(workOrderId, data),
    onSuccess: () => { setForm(empty); setOpen(false); invalidate() },
    onError: () => toast({ title: 'Failed to add time log', variant: 'destructive' }),
  })

  const deleteLog = useMutation({
    mutationFn: (id: number) => fieldServiceApi.deleteTimeLog(id),
    onSuccess: invalidate,
    onError: () => toast({ title: 'Failed to delete time log', variant: 'destructive' }),
  })

  const totalMinutes = timeLogs.reduce((s, l) => s + (l.duration_minutes ?? 0), 0)
  const totalLabor = timeLogs.reduce((s, l) => s + l.line_total, 0)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.start_time) return
    addLog.mutate(form)
  }

  return (
    <div className="space-y-3">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Description</TableHead>
            <TableHead className="w-40">Start</TableHead>
            <TableHead className="w-40">End</TableHead>
            <TableHead className="w-24 text-right">Duration</TableHead>
            <TableHead className="w-28 text-right">Labor Cost</TableHead>
            <TableHead className="w-20 text-center">Billable</TableHead>
            {!readOnly && <TableHead className="w-10" />}
          </TableRow>
        </TableHeader>
        <TableBody>
          {timeLogs.map(log => (
            <TableRow key={log.id}>
              <TableCell className="text-sm">{log.description ?? '—'}</TableCell>
              <TableCell className="text-sm text-muted-foreground">
                {new Date(log.start_time).toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' })}
              </TableCell>
              <TableCell className="text-sm text-muted-foreground">
                {log.end_time
                  ? new Date(log.end_time).toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' })
                  : <span className="text-amber-500 font-medium">In progress</span>}
              </TableCell>
              <TableCell className="text-right text-sm">{formatDuration(log.duration_minutes)}</TableCell>
              <TableCell className="text-right text-sm font-medium">{Number(log.line_total).toFixed(2)}</TableCell>
              <TableCell className="text-center text-xs">
                <span className={log.is_billable ? 'text-green-600' : 'text-muted-foreground'}>
                  {log.is_billable ? 'Yes' : 'No'}
                </span>
              </TableCell>
              {!readOnly && (
                <TableCell>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 text-destructive hover:text-destructive"
                    onClick={() => deleteLog.mutate(log.id)}
                    disabled={deleteLog.isPending}
                  >
                    <Icon icon="solar:trash-bin-trash-bold-duotone" width={14} />
                  </Button>
                </TableCell>
              )}
            </TableRow>
          ))}
          {timeLogs.length === 0 && (
            <TableRow>
              <TableCell colSpan={readOnly ? 6 : 7} className="text-center text-sm text-muted-foreground py-4">
                No time logs yet.
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>

      <div className="flex items-center justify-between">
        {!readOnly && (
          <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
            <Icon icon="solar:clock-circle-bold-duotone" width={16} className="mr-1" />
            Log Time
          </Button>
        )}
        <div className="ml-auto text-sm font-semibold space-x-4">
          <span>Total: {formatDuration(totalMinutes)}</span>
          <span>Labor Cost: {totalLabor.toFixed(2)}</span>
        </div>
      </div>

      {/* Add time log dialog */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Log Time</DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <Label>Description</Label>
              <Textarea
                placeholder="What was done..."
                value={form.description}
                onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                rows={2}
                className="mt-1"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Start Time *</Label>
                <Input
                  type="datetime-local"
                  required
                  value={form.start_time}
                  onChange={e => setForm(f => ({ ...f, start_time: e.target.value }))}
                  className="mt-1"
                />
              </div>
              <div>
                <Label>End Time</Label>
                <Input
                  type="datetime-local"
                  value={form.end_time}
                  onChange={e => setForm(f => ({ ...f, end_time: e.target.value }))}
                  className="mt-1"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Hourly Rate</Label>
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.hourly_rate}
                  onChange={e => setForm(f => ({ ...f, hourly_rate: parseFloat(e.target.value) || 0 }))}
                  className="mt-1"
                />
              </div>
              <div className="flex items-end gap-2 pb-1">
                <Switch
                  checked={form.is_billable}
                  onCheckedChange={v => setForm(f => ({ ...f, is_billable: v }))}
                />
                <Label>Billable</Label>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={addLog.isPending}>
                {addLog.isPending ? 'Saving...' : 'Save'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
