import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'

interface StorefrontSettings {
  enabled: boolean
  currency_code: string | null
  tax_display: 'inclusive' | 'exclusive'
  guest_checkout: boolean
  allow_backorder: boolean
  low_stock_threshold: number
  cart_ttl_days: number
  checkout_terms_url: string | null
  policy_returns_url: string | null
  policy_shipping_url: string | null
  policy_privacy_url: string | null
  runtime_version: string
}

export function StorefrontSettingsPage() {
  const queryClient = useQueryClient()
  const hasPermission = useAuthStore((state) => state.hasPermission)
  const [form, setForm] = useState<StorefrontSettings | null>(null)
  const [saved, setSaved] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [starterResult, setStarterResult] = useState<string | null>(null)
  const [starterError, setStarterError] = useState<string | null>(null)

  const { isLoading } = useQuery({
    queryKey: ['storefront-settings'],
    queryFn: async () => {
      const { data } = await api.get('/admin/storefront/settings')
      setForm(data.data)
      return data.data
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      const { data } = await api.put('/admin/storefront/settings', {
        enabled: form?.enabled,
        currency_code: form?.currency_code || null,
        tax_display: form?.tax_display,
        guest_checkout: form?.guest_checkout,
        allow_backorder: form?.allow_backorder,
        low_stock_threshold: form?.low_stock_threshold,
        cart_ttl_days: form?.cart_ttl_days,
        checkout_terms_url: form?.checkout_terms_url || null,
        policy_returns_url: form?.policy_returns_url || null,
        policy_shipping_url: form?.policy_shipping_url || null,
        policy_privacy_url: form?.policy_privacy_url || null,
      })
      return data.data
    },
    onSuccess: (savedSettings) => {
      setForm(savedSettings)
      setError(null)
      setSaved('Settings saved.')
      queryClient.invalidateQueries({ queryKey: ['storefront-settings'] })
      window.setTimeout(() => setSaved(null), 3000)
    },
    onError: (e: unknown) => {
      const message = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
      setError(message?.message ?? 'Could not save settings. Check the highlighted fields.')
    },
  })

  useEffect(() => {
    if (isLoading) setSaved(null)
  }, [isLoading])

  const insertStarterPages = useMutation({
    mutationFn: async () => {
      const { data } = await api.post('/website-builder/storefront/pages/starter')
      return data as {
        message: string
        data?: { created: string[]; skipped: string[] }
      }
    },
    onSuccess: (res) => {
      setStarterError(null)
      const created = res.data?.created ?? []
      const skipped = res.data?.skipped ?? []
      setStarterResult(
        created.length > 0
          ? `Inserted ${created.length} page(s)${skipped.length ? `, skipped ${skipped.length} existing` : ''}. Review them in the Website Builder, then Publish.`
          : 'All starter pages already exist — nothing was changed.',
      )
    },
    onError: (e: unknown) => {
      setStarterResult(null)
      const message = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setStarterError(message ?? 'Could not insert starter pages.')
    },
  })

  if (isLoading || !form) {
    return (
      <div className="flex h-[50vh] items-center justify-center">
        <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const toggle = (key: keyof StorefrontSettings) => setForm({ ...form, [key]: !form[key] })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Storefront Settings</h1>
        <p className="text-muted-foreground">
          Shop behaviour for this tenant. Nothing here is tenant-specific — pages are built in the Website
          Builder with Store blocks.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Shop</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.enabled} onChange={() => toggle('enabled')} />
            <span>Storefront enabled (turn off to hide shop functions on published pages)</span>
          </label>

          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-1">
              <Label htmlFor="currency">Currency code</Label>
              <Input
                id="currency"
                maxLength={3}
                placeholder="USD"
                value={form.currency_code ?? ''}
                onChange={(e) => setForm({ ...form, currency_code: e.target.value.toUpperCase() })}
              />
              <p className="text-xs text-muted-foreground">Shown on prices; leave empty to show amounts without a symbol.</p>
            </div>
            <div className="space-y-1">
              <Label htmlFor="tax">Tax display</Label>
              <select
                id="tax"
                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                value={form.tax_display}
                onChange={(e) => setForm({ ...form, tax_display: e.target.value as 'inclusive' | 'exclusive' })}
              >
                <option value="exclusive">Exclusive of tax</option>
                <option value="inclusive">Inclusive of tax</option>
              </select>
            </div>
            <div className="space-y-1">
              <Label htmlFor="cart_ttl">Cart lifetime (days)</Label>
              <Input
                id="cart_ttl"
                type="number"
                min={1}
                max={365}
                value={form.cart_ttl_days}
                onChange={(e) => setForm({ ...form, cart_ttl_days: Number(e.target.value) })}
              />
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={form.guest_checkout} onChange={() => toggle('guest_checkout')} />
              <span>Allow guest checkout</span>
            </label>
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={form.allow_backorder} onChange={() => toggle('allow_backorder')} />
              <span>Allow backorders when out of stock</span>
            </label>
            <div className="space-y-1">
              <Label htmlFor="low_stock">Low-stock threshold</Label>
              <Input
                id="low_stock"
                type="number"
                min={0}
                value={form.low_stock_threshold}
                onChange={(e) => setForm({ ...form, low_stock_threshold: Number(e.target.value) })}
              />
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Policies & terms</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {(
            [
              ['checkout_terms_url', 'Checkout terms URL'],
              ['policy_returns_url', 'Returns policy URL'],
              ['policy_shipping_url', 'Shipping policy URL'],
              ['policy_privacy_url', 'Privacy policy URL'],
            ] as const
          ).map(([key, label]) => (
            <div key={key} className="space-y-1">
              <Label htmlFor={key}>{label}</Label>
              <Input
                id={key}
                placeholder="/p/returns or https://…"
                value={(form[key] ?? '') as string}
                onChange={(e) => setForm({ ...form, [key]: e.target.value })}
              />
            </div>
          ))}
        </CardContent>
      </Card>

      {hasPermission('storefront.write') && (
        <Card>
          <CardHeader>
            <CardTitle>Store pages</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <p className="text-sm text-muted-foreground">
              Insert the recommended starter shop pages (Shop, Category, Product, Cart, Checkout, Account,
              Order confirmation, Search) into your Website Builder as drafts — review, adjust, then Publish.
              Existing pages are never overwritten.
            </p>
            <Button variant="outline" onClick={() => insertStarterPages.mutate()} disabled={insertStarterPages.isPending}>
              {insertStarterPages.isPending ? 'Inserting…' : 'Insert store pages'}
            </Button>
            {starterResult && <p className="text-sm text-green-700">{starterResult}</p>}
            {starterError && <p role="alert" className="text-sm text-destructive">{starterError}</p>}
          </CardContent>
        </Card>
      )}

      <div className="flex items-center gap-3">
        <Button onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save settings'}
        </Button>
        {saved && <span className="text-sm text-green-700">{saved}</span>}
        {error && <span role="alert" className="text-sm text-destructive">{error}</span>}
      </div>
    </div>
  )
}

export default StorefrontSettingsPage
