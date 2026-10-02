import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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
import { prettyLabel, fmtDate } from './format'

interface Promotion {
  id: number; code: string; type: string; value: string; min_subtotal: string | null
  usage_limit: number | null; per_account_limit: number | null; redemptions_count: number
  starts_at: string | null; ends_at: string | null; is_active: boolean; description: string | null
}
interface Redemption { id: number; order_id: number; account_id: number | null; amount: string; redeemed_at: string | null }

const TYPES = ['percent', 'fixed', 'free_shipping']
const emptyForm = { code: '', type: 'percent', value: '', min_subtotal: '', usage_limit: '', per_account_limit: '', starts_at: '', ends_at: '', is_active: true, description: '' }
function errMsg(e: unknown, f: string): string { return (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? f }

export function StorefrontPromotionsPage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [page, setPage] = useState(1)
  const [type, setType] = useState('all')
  const [active, setActive] = useState('all')
  const [editing, setEditing] = useState<Promotion | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState<typeof emptyForm>(emptyForm)
  const [redemptionsFor, setRedemptionsFor] = useState<Promotion | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['storefront-promotions', page, type, active],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: 20 }
      if (type !== 'all') params.type = type
      if (active !== 'all') params.active = active
      const { data: resp } = await api.get('/admin/storefront/promotions', { params })
      return resp as { data: Promotion[]; meta: { page: number; total: number } }
    },
  })

  const { data: redemptions } = useQuery({
    queryKey: ['storefront-redemptions', redemptionsFor?.id],
    enabled: !!redemptionsFor,
    queryFn: async () => (await api.get(`/admin/storefront/promotions/${redemptionsFor!.id}/redemptions`)).data as { data: Redemption[] },
  })

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = Math.max(1, Math.ceil(total / 20))

  const openNew = () => { setEditing(null); setForm(emptyForm); setFormOpen(true) }
  const openEdit = (p: Promotion) => {
    setEditing(p)
    setForm({
      code: p.code, type: p.type, value: p.value, min_subtotal: p.min_subtotal ?? '',
      usage_limit: p.usage_limit?.toString() ?? '', per_account_limit: p.per_account_limit?.toString() ?? '',
      starts_at: p.starts_at?.slice(0, 10) ?? '', ends_at: p.ends_at?.slice(0, 10) ?? '',
      is_active: p.is_active, description: p.description ?? '',
    })
    setFormOpen(true)
  }

  const save = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = {
        code: form.code, type: form.type, value: Number(form.value),
        min_subtotal: form.min_subtotal ? Number(form.min_subtotal) : null,
        usage_limit: form.usage_limit ? Number(form.usage_limit) : null,
        per_account_limit: form.per_account_limit ? Number(form.per_account_limit) : null,
        starts_at: form.starts_at || null, ends_at: form.ends_at || null,
        is_active: form.is_active, description: form.description || null,
      }
      return editing
        ? (await api.put(`/admin/storefront/promotions/${editing.id}`, body)).data
        : (await api.post('/admin/storefront/promotions', body)).data
    },
    onSuccess: () => { toast({ title: editing ? 'Promotion updated' : 'Promotion created' }); setFormOpen(false); queryClient.invalidateQueries({ queryKey: ['storefront-promotions'] }) },
    onError: (e) => toast({ title: 'Save failed', description: errMsg(e, 'Could not save the promotion.'), variant: 'destructive' }),
  })

  const valueLabel = (p: Promotion) => p.type === 'percent' ? `${p.value}%` : p.type === 'fixed' ? p.value : 'Free shipping'

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div><h1 className="text-3xl font-bold">Promotions</h1>
          <p className="text-muted-foreground">Discount codes — percent, fixed amount or free shipping. Redemptions are DB-enforced (single-use per order).</p></div>
        <Button onClick={openNew}><Icon icon="solar:add-circle-bold-duotone" className="mr-1 h-4 w-4" /> New promotion</Button>
      </div>

      <Card>
        <CardContent className="flex items-end gap-3 pt-6">
          <div className="w-44"><Label className="text-xs text-muted-foreground">Type</Label>
            <Select value={type} onValueChange={(v) => { setType(v); setPage(1) }}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent><SelectItem value="all">All types</SelectItem>{TYPES.map((t) => <SelectItem key={t} value={t}>{prettyLabel(t)}</SelectItem>)}</SelectContent>
            </Select></div>
          <div className="w-44"><Label className="text-xs text-muted-foreground">Active</Label>
            <Select value={active} onValueChange={(v) => { setActive(v); setPage(1) }}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent><SelectItem value="all">All</SelectItem><SelectItem value="1">Active</SelectItem><SelectItem value="0">Inactive</SelectItem></SelectContent>
            </Select></div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex h-40 items-center justify-center text-muted-foreground"><Icon icon="solar:loading-bold-duotone" className="h-6 w-6 animate-spin" /></div>
          ) : rows.length === 0 ? (
            <div className="p-10 text-center text-muted-foreground"><Icon icon="solar:ticket-sale-line-duotone" className="mx-auto mb-2 h-8 w-8" />No promotions yet.</div>
          ) : (
            <Table>
              <TableHeader><TableRow>
                <TableHead>Code</TableHead><TableHead>Type</TableHead><TableHead className="text-right">Value</TableHead>
                <TableHead className="text-right">Used</TableHead><TableHead>Window</TableHead><TableHead>Active</TableHead><TableHead className="text-right">Actions</TableHead>
              </TableRow></TableHeader>
              <TableBody>
                {rows.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell className="font-mono font-medium">{p.code}</TableCell>
                    <TableCell>{prettyLabel(p.type)}</TableCell>
                    <TableCell className="text-right">{valueLabel(p)}</TableCell>
                    <TableCell className="text-right">{p.redemptions_count}{p.usage_limit ? `/${p.usage_limit}` : ''}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">{p.starts_at ? fmtDate(p.starts_at) : '—'} → {p.ends_at ? fmtDate(p.ends_at) : '∞'}</TableCell>
                    <TableCell>{p.is_active ? <Badge className="bg-green-100 text-green-800" variant="secondary">Active</Badge> : <Badge variant="outline">Off</Badge>}</TableCell>
                    <TableCell><div className="flex justify-end gap-2">
                      <Button size="sm" variant="outline" onClick={() => setRedemptionsFor(p)}>Redemptions</Button>
                      <Button size="sm" variant="outline" onClick={() => openEdit(p)}>Edit</Button>
                    </div></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-between text-sm text-muted-foreground">
        <span>{total} promotion{total === 1 ? '' : 's'}</span>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
          <span>Page {page} of {lastPage}</span>
          <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>Next</Button>
        </div>
      </div>

      {/* Create / edit */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader><DialogTitle>{editing ? `Edit ${editing.code}` : 'New promotion'}</DialogTitle></DialogHeader>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2"><Label>Code *</Label>
              <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="SAVE10" disabled={!!editing} /></div>
            <div><Label>Type *</Label>
              <Select value={form.type} onValueChange={(v) => setForm({ ...form, type: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{TYPES.map((t) => <SelectItem key={t} value={t}>{prettyLabel(t)}</SelectItem>)}</SelectContent>
              </Select></div>
            <div><Label>Value *</Label>
              <Input type="number" step="0.01" min="0" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })}
                placeholder={form.type === 'percent' ? '10' : form.type === 'fixed' ? '5.00' : '0'} disabled={form.type === 'free_shipping'} /></div>
            <div><Label>Min subtotal</Label><Input type="number" step="0.01" min="0" value={form.min_subtotal} onChange={(e) => setForm({ ...form, min_subtotal: e.target.value })} /></div>
            <div><Label>Usage limit</Label><Input type="number" min="1" value={form.usage_limit} onChange={(e) => setForm({ ...form, usage_limit: e.target.value })} /></div>
            <div><Label>Per-account limit</Label><Input type="number" min="1" value={form.per_account_limit} onChange={(e) => setForm({ ...form, per_account_limit: e.target.value })} /></div>
            <div><Label>Starts</Label><Input type="date" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></div>
            <div><Label>Ends</Label><Input type="date" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></div>
            <div className="sm:col-span-2"><Label>Description</Label><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} maxLength={255} /></div>
            <label className="flex items-center gap-2 sm:col-span-2"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /><span>Active</span></label>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>Cancel</Button>
            <Button disabled={!form.code || save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Redemptions */}
      <Dialog open={!!redemptionsFor} onOpenChange={(o) => { if (!o) setRedemptionsFor(null) }}>
        <DialogContent className="max-w-lg">
          <DialogHeader><DialogTitle>{redemptionsFor?.code} — redemptions</DialogTitle></DialogHeader>
          {(redemptions?.data ?? []).length === 0 ? <p className="text-sm text-muted-foreground">No redemptions yet.</p> : (
            <Table>
              <TableHeader><TableRow><TableHead>Order</TableHead><TableHead>Account</TableHead><TableHead className="text-right">Amount</TableHead><TableHead>When</TableHead></TableRow></TableHeader>
              <TableBody>
                {(redemptions?.data ?? []).map((r) => (
                  <TableRow key={r.id}><TableCell>#{r.order_id}</TableCell><TableCell>{r.account_id ?? 'guest'}</TableCell>
                    <TableCell className="text-right">{r.amount}</TableCell><TableCell className="text-xs text-muted-foreground">{fmtDate(r.redeemed_at)}</TableCell></TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default StorefrontPromotionsPage
