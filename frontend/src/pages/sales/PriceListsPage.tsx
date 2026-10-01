import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'
import { cn, getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'
import pricingApi, {
  type PriceList,
  type PriceListApplicationPayload,
  type PriceListItemPayload,
} from '@/lib/pricingApi'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'

/**
 * Price Lists admin — MRD pricelist-multicurrency-mrd §11.1.
 * Header (currency, rounding, display policy, validity) + ordered rules +
 * applicability. Manage actions are gated by `pricelists.manage`; cost-rule
 * internals are server-masked for roles without `pricelists.cost` (FR-3.6).
 */

interface ItemDraft extends PriceListItemPayload {
  key: string
}

interface ApplicationDraft extends PriceListApplicationPayload {
  key: string
}

interface ListDraft {
  id?: number
  name: string
  code: string
  currency_code: string
  description: string
  sequence: number
  valid_from: string
  valid_until: string
  is_active: boolean
  rounding_enabled: boolean
  rounding_method: 'up' | 'down' | 'nearest'
  rounding_precision: number
  discount_display: 'show_discount' | 'show_discounted_price'
  items: ItemDraft[]
  applications: ApplicationDraft[]
}

const emptyItem = (sequence = 10): ItemDraft => ({
  key: `item-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
  sequence,
  apply_on: 'product',
  product_id: null,
  category_id: null,
  min_quantity: 1,
  max_quantity: null,
  date_start: null,
  date_end: null,
  compute_type: 'percentage',
  fixed_price: null,
  percent: 10,
  base: null,
  other_price_list_id: null,
  markup_percent: null,
})

const emptyDraft = (currency = 'USD'): ListDraft => ({
  name: '',
  code: '',
  currency_code: currency,
  description: '',
  sequence: 10,
  valid_from: '',
  valid_until: '',
  is_active: true,
  rounding_enabled: false,
  rounding_method: 'nearest',
  rounding_precision: 0.01,
  discount_display: 'show_discounted_price',
  items: [emptyItem()],
  applications: [{ key: `app-${Date.now()}`, applies_to_type: 'all', applies_to_id: null }],
})

export default function PriceListsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const hasPermission = useAuthStore((state) => state.hasPermission)
  const canManage = hasPermission('pricelists.manage')

  const [search, setSearch] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [draft, setDraft] = useState<ListDraft>(emptyDraft())
  const [saveError, setSaveError] = useState<string | null>(null)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

  const { data: currencies = [] } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  })

  const { data: categoriesResponse } = useQuery({
    queryKey: ['product-categories'],
    queryFn: async () => (await api.get('/product-categories')).data,
  })
  const categoryRows: any[] = (categoriesResponse as any)?.data ?? categoriesResponse ?? []

  const { data: lists, isLoading } = useQuery({
    queryKey: ['price-lists', search],
    queryFn: () => pricingApi.listPriceLists({ search: search || undefined }),
  })

  // Active lists for the formula "other pricelist" picker (independent of
  // the search filter applied to the management table).
  const { data: activeListsResponse } = useQuery({
    queryKey: ['price-lists', 'active-picker'],
    queryFn: () => pricingApi.listPriceLists({ active_only: true, per_page: 200 }),
  })
  const priceListRows: any[] = (activeListsResponse as any)?.data ?? activeListsResponse ?? []

  const rows: PriceList[] = lists?.data ?? lists ?? []

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['price-lists'] })
  }

  const saveMutation = useMutation({
    mutationFn: (payload: ListDraft) => {
      const body = {
        name: payload.name,
        code: payload.code || null,
        currency_code: payload.currency_code,
        description: payload.description || null,
        sequence: Number(payload.sequence) || 10,
        valid_from: payload.valid_from || null,
        valid_until: payload.valid_until || null,
        is_active: payload.is_active,
        rounding_enabled: payload.rounding_enabled,
        rounding_method: payload.rounding_method,
        rounding_precision: Number(payload.rounding_precision) || 0.01,
        discount_display: payload.discount_display,
        items: payload.items.map((item, index) => ({
          sequence: Number(item.sequence) || (index + 1) * 10,
          apply_on: item.apply_on,
          product_id: item.apply_on === 'product' ? item.product_id : null,
          category_id: item.apply_on === 'category' ? item.category_id : null,
          min_quantity: item.min_quantity ?? 1,
          max_quantity: item.max_quantity ?? null,
          date_start: item.date_start || null,
          date_end: item.date_end || null,
          compute_type: item.compute_type === 'restricted' ? 'formula' : item.compute_type,
          fixed_price: item.compute_type === 'fixed' ? item.fixed_price : null,
          percent: item.percent ?? null,
          base: item.compute_type === 'formula' ? item.base || 'list_price' : null,
          other_price_list_id: item.base === 'other_pricelist' ? item.other_price_list_id : null,
          markup_percent: item.markup_percent ?? null,
        })),
        applications: payload.applications.map((app) => ({
          applies_to_type: app.applies_to_type,
          applies_to_id: app.applies_to_type === 'all' ? null : app.applies_to_id,
        })),
      }

      return payload.id
        ? pricingApi.updatePriceList(payload.id, body)
        : pricingApi.createPriceList(body)
    },
    onSuccess: () => {
      toast({ title: draft.id ? 'Price list updated' : 'Price list created' })
      setDialogOpen(false)
      setSaveError(null)
      invalidate()
    },
    onError: (error: any) => {
      const message = getApiErrorMessage(error)
      setSaveError(message)
      toast({ variant: 'destructive', title: 'Could not save price list', description: message })
    },
  })

  const cloneMutation = useMutation({
    mutationFn: (id: number) => pricingApi.clonePriceList(id),
    onSuccess: () => {
      toast({ title: 'Price list cloned', description: 'The copy starts inactive so you can review it first.' })
      invalidate()
    },
    onError: (error: any) => toast({ variant: 'destructive', title: 'Clone failed', description: getApiErrorMessage(error) }),
  })

  const deactivateMutation = useMutation({
    mutationFn: (id: number) => pricingApi.deactivatePriceList(id),
    onSuccess: () => {
      toast({ title: 'Price list deactivated', description: 'Historical documents remain reproducible.' })
      invalidate()
    },
    onError: (error: any) => toast({ variant: 'destructive', title: 'Deactivate failed', description: getApiErrorMessage(error) }),
  })

  const exportItems = async () => {
    if (!draft.id) return
    try {
      const response = await pricingApi.exportItems(draft.id)
      const url = URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }))
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = `price-list-${draft.code || draft.id}-items.csv`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      URL.revokeObjectURL(url)
    } catch (error: any) {
      toast({ variant: 'destructive', title: 'Export failed', description: getApiErrorMessage(error) })
    }
  }

  const importItems = async (file: File) => {
    if (!draft.id) return
    try {
      const result = await pricingApi.importItems(draft.id, file)
      toast({ title: 'Import complete', description: `${result?.imported ?? 0} rule(s) replaced.` })
      const full: PriceList = await pricingApi.getPriceList(draft.id)
      setDraft(draftFromList(full))
      setSaveError(null)
      invalidate()
    } catch (error: any) {
      const message = getApiErrorMessage(error)
      setSaveError(message)
      toast({ variant: 'destructive', title: 'Import failed', description: message })
    } finally {
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  const openCreate = () => {
    setDraft(emptyDraft(currencies[0]?.code ?? 'USD'))
    setSaveError(null)
    setDialogOpen(true)
  }

  const draftFromList = (full: PriceList): ListDraft => ({
    id: full.id,
    name: full.name,
    code: full.code ?? '',
    currency_code: full.currency_code,
    description: full.description ?? '',
    sequence: full.sequence,
    valid_from: full.valid_from ?? '',
    valid_until: full.valid_until ?? '',
    is_active: full.is_active,
    rounding_enabled: full.rounding_enabled,
    rounding_method: full.rounding_method,
    rounding_precision: Number(full.rounding_precision),
    discount_display: full.discount_display,
    items: (full.items ?? []).map((item, index) => ({
      ...emptyItem((index + 1) * 10),
      ...item,
      compute_type: (item.compute_type as any) === 'restricted' ? 'formula' : item.compute_type,
    })),
    applications: (full.applications ?? []).length
      ? (full.applications ?? []).map((app) => ({ ...app, key: `app-${app.id ?? Math.random()}` }))
      : [{ key: `app-${Date.now()}`, applies_to_type: 'all', applies_to_id: null }],
  })

  const openEdit = async (list: PriceList) => {
    const full: PriceList = await pricingApi.getPriceList(list.id)
    setDraft(draftFromList(full))
    setSaveError(null)
    setDialogOpen(true)
  }

  const updateDraft = (patch: Partial<ListDraft>) => setDraft((current) => ({ ...current, ...patch }))

  const updateItem = (key: string, patch: Partial<ItemDraft>) =>
    setDraft((current) => ({
      ...current,
      items: current.items.map((item) => (item.key === key ? { ...item, ...patch } : item)),
    }))

  const itemSummary = useMemo(
    () =>
      (item: ItemDraft): string => {
        if (item.compute_type === 'restricted') return 'Cost-based rule (restricted)'
        if (item.compute_type === 'fixed') return `Fixed ${item.fixed_price ?? 0}`
        if (item.compute_type === 'percentage') {
          const pct = Number(item.percent ?? 0)
          return pct >= 0 ? `−${pct}% off list` : `+${Math.abs(pct)}% markup`
        }
        const base = item.base === 'cost' ? 'cost' : item.base === 'other_pricelist' ? 'other list' : 'list price'
        return `Formula: ${base}${item.markup_percent ? ` +${item.markup_percent}%` : ''}${item.percent ? ` −${item.percent}%` : ''}`
      },
    [],
  )

  return (
    <div className="space-y-6 pb-10">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-3xl font-bold tracking-tight">Price Lists</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Multi-currency pricing rules applied automatically to quotations, orders, deliveries and invoices.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-64">
            <Icon
              icon="solar:magnifer-bold-duotone"
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              placeholder="Search name or code…"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>
          {canManage && (
            <HeaderActionButton icon="solar:add-circle-bold" onClick={openCreate}>
              New price list
            </HeaderActionButton>
          )}
        </div>
      </div>

      <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle className="text-lg">All lists</CardTitle>
          {!isLoading && (
            <span className="inline-flex items-center rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">
              {rows.length} list{rows.length === 1 ? '' : 's'}
            </span>
          )}
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
            </div>
          ) : rows.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center dark:border-slate-700">
              <Icon icon="solar:tag-price-bold-duotone" className="mx-auto mb-2 h-10 w-10 text-muted-foreground opacity-40" />
              <p className="text-sm font-medium">No price lists yet</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Create one to publish currency-specific pricing.</p>
              {canManage && (
                <div className="mt-4 flex justify-center">
                  <HeaderActionButton icon="solar:add-circle-bold" onClick={openCreate}>
                    New price list
                  </HeaderActionButton>
                </div>
              )}
            </div>
          ) : (
            <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/40 hover:bg-muted/40">
                    <TableHead>Name</TableHead>
                    <TableHead>Code</TableHead>
                    <TableHead>Currency</TableHead>
                    <TableHead className="text-right">Rules</TableHead>
                    <TableHead>Validity</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((list) => (
                    <TableRow
                      key={list.id}
                      className="cursor-pointer"
                      onClick={() => openEdit(list)}
                    >
                      <TableCell className="font-medium">{list.name}</TableCell>
                      <TableCell className="font-mono text-xs text-muted-foreground">{list.code || '—'}</TableCell>
                      <TableCell>
                        <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
                          {list.currency_code}
                        </span>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{list.items_count ?? 0}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {list.valid_from || list.valid_until
                          ? `${list.valid_from ?? '…'} → ${list.valid_until ?? '…'}`
                          : 'Always'}
                      </TableCell>
                      <TableCell>
                        <span
                          className={cn(
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                            list.is_active
                              ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                              : 'bg-muted text-muted-foreground',
                          )}
                        >
                          <span className={cn('h-1.5 w-1.5 rounded-full', list.is_active ? 'bg-emerald-500' : 'bg-muted-foreground/60')} />
                          {list.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </TableCell>
                      <TableCell className="text-right" onClick={(event) => event.stopPropagation()}>
                        <div className="flex items-center justify-end gap-1.5">
                          <HeaderActionButton
                            variant="outline"
                            className="h-7 px-2.5"
                            icon={canManage ? 'solar:pen-bold-duotone' : 'solar:eye-bold-duotone'}
                            onClick={() => openEdit(list)}
                          >
                            {canManage ? 'Edit' : 'View'}
                          </HeaderActionButton>
                          {canManage && (
                            <>
                              <HeaderActionButton
                                variant="outline"
                                className="h-7 px-2.5"
                                icon="solar:copy-bold-duotone"
                                onClick={() => cloneMutation.mutate(list.id)}
                              >
                                Clone
                              </HeaderActionButton>
                              {list.is_active && (
                                <HeaderActionButton
                                  variant="outline"
                                  className="h-7 px-2.5 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                  icon="solar:close-circle-bold-duotone"
                                  onClick={() => deactivateMutation.mutate(list.id)}
                                >
                                  Deactivate
                                </HeaderActionButton>
                              )}
                            </>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90vh] max-w-4xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{draft.id ? 'Edit price list' : 'New price list'}</DialogTitle>
            <DialogDescription>
              Rules are evaluated top-down; the first matching rule prices the line. Fixed prices are in the list
              currency.
            </DialogDescription>
          </DialogHeader>

          {!canManage ? (
            <p className="text-sm text-muted-foreground">
              You have view access only — ask an administrator for the price-list management permission.
            </p>
          ) : (
            <div className="space-y-6">
              {saveError && (
                <div className="rounded-xl border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                  {saveError}
                </div>
              )}

              {/* Header fields */}
              <div className="grid gap-4 rounded-xl border border-slate-200/80 p-4 dark:border-slate-800 md:grid-cols-3">
                <div className="space-y-1.5">
                  <Label>Name <span className="text-destructive">*</span></Label>
                  <Input className="h-9" value={draft.name} onChange={(e) => updateDraft({ name: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Code</Label>
                  <Input className="h-9 font-mono" value={draft.code} onChange={(e) => updateDraft({ code: e.target.value })} placeholder="EXPORT-USD" />
                </div>
                <div className="space-y-1.5">
                  <Label>Currency <span className="text-destructive">*</span></Label>
                  <Select value={draft.currency_code} onValueChange={(value) => updateDraft({ currency_code: value })}>
                    <SelectTrigger className="h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {currencies.map((currency: any) => (
                        <SelectItem key={currency.code} value={currency.code}>
                          {currency.code} — {currency.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Sequence</Label>
                  <Input
                    type="number"
                    className="h-9"
                    value={draft.sequence}
                    onChange={(e) => updateDraft({ sequence: Number(e.target.value) })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Valid from</Label>
                  <Input type="date" className="h-9" value={draft.valid_from} onChange={(e) => updateDraft({ valid_from: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Valid until</Label>
                  <Input type="date" className="h-9" value={draft.valid_until} onChange={(e) => updateDraft({ valid_until: e.target.value })} />
                </div>
              </div>

              {/* Behaviour switches + rounding */}
              <div className="grid gap-4 md:grid-cols-4">
                <div className="flex items-center justify-between rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                  <div>
                    <p className="text-sm font-medium">Active</p>
                    <p className="text-xs text-muted-foreground">Inactive lists are skipped</p>
                  </div>
                  <Switch checked={draft.is_active} onCheckedChange={(checked) => updateDraft({ is_active: checked })} />
                </div>
                <div className="flex items-center justify-between rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                  <div>
                    <p className="text-sm font-medium">Rounding</p>
                    <p className="text-xs text-muted-foreground">Applied to unit price</p>
                  </div>
                  <Switch checked={draft.rounding_enabled} onCheckedChange={(checked) => updateDraft({ rounding_enabled: checked })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Rounding method</Label>
                  <Select
                    value={draft.rounding_method}
                    onValueChange={(value: any) => updateDraft({ rounding_method: value })}
                  >
                    <SelectTrigger className="h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="nearest">Nearest</SelectItem>
                      <SelectItem value="up">Up</SelectItem>
                      <SelectItem value="down">Down</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Rounding step</Label>
                  <Input
                    type="number"
                    step="0.01"
                    className="h-9"
                    value={draft.rounding_precision}
                    onChange={(e) => updateDraft({ rounding_precision: Number(e.target.value) })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Discount display</Label>
                  <Select
                    value={draft.discount_display}
                    onValueChange={(value: any) => updateDraft({ discount_display: value })}
                  >
                    <SelectTrigger className="h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="show_discounted_price">Show discounted price</SelectItem>
                      <SelectItem value="show_discount">Show public price + discount</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-1.5">
                <Label>Description</Label>
                <Textarea
                  rows={2}
                  value={draft.description}
                  onChange={(e) => updateDraft({ description: e.target.value })}
                  placeholder="Optional notes about this price list"
                />
              </div>

              {/* ── Applicability ─────────────────────────────────────── */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold">Applies to</h3>
                  <HeaderActionButton
                    type="button"
                    variant="outline"
                    icon="solar:add-circle-bold"
                    onClick={() =>
                      updateDraft({
                        applications: [
                          ...draft.applications,
                          { key: `app-${Date.now()}`, applies_to_type: 'customer', applies_to_id: null },
                        ],
                      })
                    }
                  >
                    Add applicability
                  </HeaderActionButton>
                </div>
                {draft.applications.map((app) => (
                  <div
                    key={app.key}
                    className="grid items-center gap-2 rounded-xl border border-slate-200/80 p-3 dark:border-slate-800 md:grid-cols-[180px_1fr_auto]"
                  >
                    <Select
                      value={app.applies_to_type}
                      onValueChange={(value: any) =>
                        updateDraft({
                          applications: draft.applications.map((entry) =>
                            entry.key === app.key ? { ...entry, applies_to_type: value, applies_to_id: null } : entry,
                          ),
                        })
                      }
                    >
                      <SelectTrigger className="h-8 rounded-full text-xs">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All customers</SelectItem>
                        <SelectItem value="customer">Specific customer</SelectItem>
                        <SelectItem value="customer_group">Customer group (category id)</SelectItem>
                      </SelectContent>
                    </Select>
                    {app.applies_to_type === 'customer' ? (
                      <CustomerSearchCombobox
                        value={app.applies_to_id ? String(app.applies_to_id) : ''}
                        onValueChange={(value) =>
                          updateDraft({
                            applications: draft.applications.map((entry) =>
                              entry.key === app.key ? { ...entry, applies_to_id: value ? Number(value) : null } : entry,
                            ),
                          })
                        }
                      />
                    ) : app.applies_to_type === 'customer_group' ? (
                      <Input
                        type="number"
                        className="h-8"
                        placeholder="Customer category id"
                        value={app.applies_to_id ?? ''}
                        onChange={(e) =>
                          updateDraft({
                            applications: draft.applications.map((entry) =>
                              entry.key === app.key
                                ? { ...entry, applies_to_id: e.target.value ? Number(e.target.value) : null }
                                : entry,
                            ),
                          })
                        }
                      />
                    ) : (
                      <p className="text-sm text-muted-foreground">Every customer without a more specific list.</p>
                    )}
                    <HeaderActionButton
                      type="button"
                      variant="ghost"
                      className="h-8 px-2.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                      icon="solar:trash-bin-trash-bold-duotone"
                      disabled={draft.applications.length === 1}
                      onClick={() =>
                        updateDraft({ applications: draft.applications.filter((entry) => entry.key !== app.key) })
                      }
                    >
                      Remove
                    </HeaderActionButton>
                  </div>
                ))}
              </div>

              {/* ── Rules ─────────────────────────────────────────────── */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold">Rules</h3>
                    <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                      {draft.items.length} · first match wins
                    </span>
                  </div>
                  <HeaderActionButton
                    type="button"
                    variant="outline"
                    icon="solar:add-circle-bold"
                    onClick={() => updateDraft({ items: [...draft.items, emptyItem((draft.items.length + 1) * 10)] })}
                  >
                    Add rule
                  </HeaderActionButton>
                </div>

                {draft.items.map((item) => (
                  <div key={item.key} className="space-y-3 rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                    <div className="grid items-end gap-3 md:grid-cols-12">
                      <div className="space-y-1 md:col-span-1">
                        <Label className="text-xs">Seq</Label>
                        <Input
                          type="number"
                          className="h-8"
                          value={item.sequence}
                          onChange={(e) => updateItem(item.key, { sequence: Number(e.target.value) })}
                        />
                      </div>
                      <div className="space-y-1 md:col-span-2">
                        <Label className="text-xs">Scope</Label>
                        <Select
                          value={item.apply_on}
                          onValueChange={(value: any) =>
                            updateItem(item.key, { apply_on: value, product_id: null, category_id: null })
                          }
                        >
                          <SelectTrigger className="h-8 rounded-full text-xs">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="product">Product</SelectItem>
                            <SelectItem value="category">Category</SelectItem>
                            <SelectItem value="all">Any product</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      {item.apply_on === 'product' && (
                        <div className="space-y-1 md:col-span-3">
                          <Label className="text-xs">Product</Label>
                          <ProductSearchCombobox
                            value={item.product_id ? String(item.product_id) : ''}
                            onValueChange={(value) => updateItem(item.key, { product_id: value ? Number(value) : null })}
                            placeholder="Search name, SKU, barcode, lot or serial..."
                          />
                        </div>
                      )}
                      {item.apply_on === 'category' && (
                        <div className="space-y-1 md:col-span-3">
                          <Label className="text-xs">Category</Label>
                          <Select
                            value={item.category_id ? String(item.category_id) : ''}
                            onValueChange={(value) => updateItem(item.key, { category_id: value ? Number(value) : null })}
                          >
                            <SelectTrigger className="h-8 rounded-full text-xs">
                              <SelectValue placeholder="Select category" />
                            </SelectTrigger>
                            <SelectContent>
                              {categoryRows.map((category: any) => (
                                <SelectItem key={category.id} value={String(category.id)}>
                                  {category.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>
                      )}
                      {item.apply_on === 'all' && <div className="hidden md:col-span-3 md:block" />}
                      <div className="space-y-1 md:col-span-1">
                        <Label className="text-xs">Min qty</Label>
                        <Input
                          type="number"
                          className="h-8"
                          value={item.min_quantity ?? 1}
                          onChange={(e) => updateItem(item.key, { min_quantity: Number(e.target.value) })}
                        />
                      </div>
                      <div className="space-y-1 md:col-span-1">
                        <Label className="text-xs">Max qty</Label>
                        <Input
                          type="number"
                          className="h-8"
                          placeholder="∞"
                          value={item.max_quantity ?? ''}
                          onChange={(e) =>
                            updateItem(item.key, { max_quantity: e.target.value === '' ? null : Number(e.target.value) })
                          }
                        />
                      </div>
                      <div className="space-y-1 md:col-span-2">
                        <Label className="text-xs">Valid from</Label>
                        <Input
                          type="date"
                          className="h-8"
                          value={item.date_start ?? ''}
                          onChange={(e) => updateItem(item.key, { date_start: e.target.value || null })}
                        />
                      </div>
                      <div className="space-y-1 md:col-span-2">
                        <Label className="text-xs">Valid to</Label>
                        <Input
                          type="date"
                          className="h-8"
                          value={item.date_end ?? ''}
                          onChange={(e) => updateItem(item.key, { date_end: e.target.value || null })}
                        />
                      </div>
                    </div>

                    <div className="grid items-end gap-3 md:grid-cols-12">
                      <div className="space-y-1 md:col-span-2">
                        <Label className="text-xs">Compute</Label>
                        <Select
                          value={item.compute_type}
                          onValueChange={(value: any) => updateItem(item.key, { compute_type: value })}
                        >
                          <SelectTrigger className="h-8 rounded-full text-xs">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="fixed">Fixed price</SelectItem>
                            <SelectItem value="percentage">% off list</SelectItem>
                            <SelectItem value="formula">Formula</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      {item.compute_type === 'fixed' && (
                        <div className="space-y-1 md:col-span-2">
                          <Label className="text-xs">Price (list ccy)</Label>
                          <Input
                            type="number"
                            step="0.01"
                            className="h-8"
                            value={item.fixed_price ?? ''}
                            onChange={(e) =>
                              updateItem(item.key, { fixed_price: e.target.value === '' ? null : Number(e.target.value) })
                            }
                          />
                        </div>
                      )}
                      {item.compute_type === 'percentage' && (
                        <div className="space-y-1 md:col-span-2">
                          <Label className="text-xs">Percent (−/+)</Label>
                          <Input
                            type="number"
                            step="0.01"
                            className="h-8"
                            value={item.percent ?? ''}
                            onChange={(e) =>
                              updateItem(item.key, { percent: e.target.value === '' ? null : Number(e.target.value) })
                            }
                          />
                        </div>
                      )}
                      {item.compute_type === 'formula' && (
                        <>
                          <div className="space-y-1 md:col-span-2">
                            <Label className="text-xs">Based on</Label>
                            <Select
                              value={item.base ?? 'list_price'}
                              onValueChange={(value: any) => updateItem(item.key, { base: value })}
                            >
                              <SelectTrigger className="h-8 rounded-full text-xs">
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="list_price">List price</SelectItem>
                                <SelectItem value="cost">Cost (guarded)</SelectItem>
                                <SelectItem value="other_pricelist">Other price list</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>
                          {item.base === 'other_pricelist' && (
                            <div className="space-y-1 md:col-span-2">
                              <Label className="text-xs">Other list</Label>
                              <Select
                                value={item.other_price_list_id ? String(item.other_price_list_id) : ''}
                                onValueChange={(value) =>
                                  updateItem(item.key, { other_price_list_id: value ? Number(value) : null })
                                }
                              >
                                <SelectTrigger className="h-8 rounded-full text-xs">
                                  <SelectValue placeholder="Select list" />
                                </SelectTrigger>
                                <SelectContent>
                                  {priceListRows
                                    .filter((list: any) => list.id !== draft.id)
                                    .map((list: any) => (
                                      <SelectItem key={list.id} value={String(list.id)}>
                                        {list.name} · {list.currency_code}
                                      </SelectItem>
                                    ))}
                                </SelectContent>
                              </Select>
                            </div>
                          )}
                          <div className="space-y-1 md:col-span-1">
                            <Label className="text-xs">Markup %</Label>
                            <Input
                              type="number"
                              step="0.01"
                              className="h-8"
                              value={item.markup_percent ?? ''}
                              onChange={(e) =>
                                updateItem(item.key, { markup_percent: e.target.value === '' ? null : Number(e.target.value) })
                              }
                            />
                          </div>
                          <div className="space-y-1 md:col-span-1">
                            <Label className="text-xs">Discount %</Label>
                            <Input
                              type="number"
                              step="0.01"
                              className="h-8"
                              value={item.percent ?? ''}
                              onChange={(e) =>
                                updateItem(item.key, { percent: e.target.value === '' ? null : Number(e.target.value) })
                              }
                            />
                          </div>
                        </>
                      )}
                      <div className="text-xs text-muted-foreground md:col-span-3">{itemSummary(item)}</div>
                      <div className="text-right md:col-span-1">
                        <HeaderActionButton
                          type="button"
                          variant="ghost"
                          className="h-8 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                          icon="solar:trash-bin-trash-bold-duotone"
                          disabled={draft.items.length === 1}
                          onClick={() => updateDraft({ items: draft.items.filter((entry) => entry.key !== item.key) })}
                          title="Remove rule"
                        >
                          <span className="sr-only">Remove</span>
                        </HeaderActionButton>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          <DialogFooter className="gap-2">
            {draft.id && (
              <>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".csv,text/csv"
                  className="hidden"
                  onChange={(event) => {
                    const file = event.target.files?.[0]
                    if (file) void importItems(file)
                  }}
                />
                <HeaderActionButton variant="outline" icon="solar:download-bold" onClick={() => void exportItems()}>
                  Export CSV
                </HeaderActionButton>
                {canManage && (
                  <HeaderActionButton variant="outline" icon="solar:upload-bold" onClick={() => fileInputRef.current?.click()}>
                    Import CSV
                  </HeaderActionButton>
                )}
              </>
            )}
            <HeaderActionButton variant="outline" onClick={() => setDialogOpen(false)}>
              Close
            </HeaderActionButton>
            {canManage && (
              <HeaderActionButton
                icon="solar:diskette-bold"
                loading={saveMutation.isPending}
                onClick={() => saveMutation.mutate(draft)}
                disabled={saveMutation.isPending || !draft.name}
              >
                Save price list
              </HeaderActionButton>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
