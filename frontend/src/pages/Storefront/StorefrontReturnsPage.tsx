import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { money, prettyLabel, fmtDate } from './format'

interface ReturnRow {
  id: number; order_id: number; order_number: string; status: string
  reason: string | null; notes: string | null; refund_amount: string; currency: string | null
  line_items: unknown[]; requested_at: string | null; received_at: string | null; refunded_at: string | null
}

const RETURN_STATUSES = ['requested', 'approved', 'rejected', 'received', 'refunded', 'closed']
const TONE: Record<string, string> = {
  requested: 'bg-amber-100 text-amber-800', approved: 'bg-blue-100 text-blue-800', rejected: 'bg-rose-100 text-rose-800',
  received: 'bg-indigo-100 text-indigo-800', refunded: 'bg-green-100 text-green-800', closed: 'bg-neutral-200 text-neutral-700',
}
function errMsg(e: unknown, f: string): string { return (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? f }

export function StorefrontReturnsPage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('all')
  const [approveFor, setApproveFor] = useState<ReturnRow | null>(null)
  const [approveAmount, setApproveAmount] = useState('')
  const [note, setNote] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['storefront-returns', page, status],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: 20 }
      if (status !== 'all') params.status = status
      const { data: resp } = await api.get('/admin/storefront/returns', { params })
      return resp as { data: ReturnRow[]; meta: { page: number; total: number } }
    },
  })

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = Math.max(1, Math.ceil(total / 20))

  const act = useMutation({
    mutationFn: async ({ id, action, body }: { id: number; action: string; body?: Record<string, unknown> }) =>
      (await api.post(`/admin/storefront/returns/${id}/${action}`, body ?? {})).data,
    onSuccess: (_d, v) => { toast({ title: `Return ${v.action}d`.replace('refundd', 'refunded').replace('received', 'received') }); setApproveFor(null); setApproveAmount(''); setNote(''); queryClient.invalidateQueries({ queryKey: ['storefront-returns'] }) },
    onError: (e) => toast({ title: 'Action failed', description: errMsg(e, 'That return action was rejected.'), variant: 'destructive' }),
  })

  const actionsFor = (r: ReturnRow) => {
    switch (r.status) {
      case 'requested': return (<>
        <Button size="sm" onClick={() => { setApproveFor(r); setApproveAmount(r.refund_amount && r.refund_amount !== '0.00' ? r.refund_amount : '') }}>Approve</Button>
        <Button size="sm" variant="outline" onClick={() => act.mutate({ id: r.id, action: 'reject', body: { note: null } })}>Reject</Button>
      </>)
      case 'approved': return (<>
        <Button size="sm" onClick={() => act.mutate({ id: r.id, action: 'receive' })}>Mark received</Button>
        <Button size="sm" variant="outline" onClick={() => act.mutate({ id: r.id, action: 'close', body: { note: null } })}>Close</Button>
      </>)
      case 'received': return (<>
        <Button size="sm" onClick={() => act.mutate({ id: r.id, action: 'refund' })}>Refund</Button>
        <Button size="sm" variant="outline" onClick={() => act.mutate({ id: r.id, action: 'close', body: { note: null } })}>Close</Button>
      </>)
      default: return <span className="text-xs text-muted-foreground">No actions</span>
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Returns</h1>
        <p className="text-muted-foreground">Request → approve → receive → refund. Refunds post through the canonical refund path.</p>
      </div>

      <Card>
        <CardContent className="flex items-end gap-3 pt-6">
          <div className="w-56">
            <Label className="text-xs text-muted-foreground">Status</Label>
            <Select value={status} onValueChange={(v) => { setStatus(v); setPage(1) }}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All statuses</SelectItem>
                {RETURN_STATUSES.map((s) => <SelectItem key={s} value={s}>{prettyLabel(s)}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex h-40 items-center justify-center text-muted-foreground"><Icon icon="solar:loading-bold-duotone" className="h-6 w-6 animate-spin" /></div>
          ) : rows.length === 0 ? (
            <div className="p-10 text-center text-muted-foreground"><Icon icon="solar:box-line-duotone" className="mx-auto mb-2 h-8 w-8" />No returns found.</div>
          ) : (
            <Table>
              <TableHeader><TableRow>
                <TableHead>Order</TableHead><TableHead>Requested</TableHead><TableHead>Reason</TableHead>
                <TableHead>Status</TableHead><TableHead className="text-right">Refund</TableHead><TableHead className="text-right">Actions</TableHead>
              </TableRow></TableHeader>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell><Link to={`/storefront/orders/${r.order_id}`} className="font-medium text-primary hover:underline">{r.order_number}</Link></TableCell>
                    <TableCell className="text-muted-foreground">{fmtDate(r.requested_at)}</TableCell>
                    <TableCell className="max-w-[220px] truncate">{r.reason ?? '—'}</TableCell>
                    <TableCell><Badge className={TONE[r.status] ?? ''} variant="secondary">{prettyLabel(r.status)}</Badge></TableCell>
                    <TableCell className="text-right font-medium">{money(r.refund_amount, r.currency)}</TableCell>
                    <TableCell><div className="flex justify-end gap-2">{actionsFor(r)}</div></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-between text-sm text-muted-foreground">
        <span>{total} return{total === 1 ? '' : 's'}</span>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
          <span>Page {page} of {lastPage}</span>
          <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>Next</Button>
        </div>
      </div>

      <Dialog open={!!approveFor} onOpenChange={(o) => { if (!o) setApproveFor(null) }}>
        <DialogContent>
          <DialogHeader><DialogTitle>Approve return {approveFor?.order_number}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div><Label>Refund amount ({approveFor?.currency}) — blank keeps the requested amount</Label>
              <Input type="number" step="0.01" min="0" value={approveAmount} onChange={(e) => setApproveAmount(e.target.value)} /></div>
            <div><Label>Note (optional)</Label><Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setApproveFor(null)}>Cancel</Button>
            <Button disabled={act.isPending} onClick={() => approveFor && act.mutate({ id: approveFor.id, action: 'approve', body: { refund_amount: approveAmount ? Number(approveAmount) : null, note: note || null } })}>
              {act.isPending ? 'Approving…' : 'Approve'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default StorefrontReturnsPage
