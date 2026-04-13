import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listPrices,
  upsertPrice,
  deletePrice,
  listRules,
  createRule,
  updateRule,
  deleteRule,
  generateQuote,
  getLatestQuote,
} from '@/lib/admin-api/pricing'
import type {
  CatalogPrice,
  CatalogPriceRule,
  TenantQuoteSnapshot,
  BillingInterval,
  PriceRuleType,
} from '@/types/admin'

// ── Helpers ───────────────────────────────────────────────────────────────────

function cents(n: number): string {
  return '$' + (n / 100).toFixed(2)
}

function Badge({ children, color }: { children: React.ReactNode; color: string }) {
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${color}`}>
      {children}
    </span>
  )
}

const INTERVAL_COLORS: Record<BillingInterval, string> = {
  monthly: 'bg-blue-100 text-blue-800',
  yearly:  'bg-purple-100 text-purple-800',
}

const RULE_TYPE_COLORS: Record<PriceRuleType, string> = {
  percentage_discount: 'bg-green-100 text-green-800',
  fixed_discount:      'bg-yellow-100 text-yellow-800',
  yearly_discount:     'bg-purple-100 text-purple-800',
  volume_tier:         'bg-orange-100 text-orange-800',
}

// ── Price Catalog Tab ─────────────────────────────────────────────────────────

function PriceCatalogTab() {
  const qc = useQueryClient()
  const { data: prices = [], isLoading } = useQuery({
    queryKey: ['pricing', 'prices'],
    queryFn: () => listPrices(),
  })

  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    catalog_item_id: '',
    billing_interval: 'monthly' as BillingInterval,
    currency: 'USD',
    unit_amount_dollars: '',
  })

  const upsert = useMutation({
    mutationFn: () =>
      upsertPrice({
        catalog_item_id:  parseInt(form.catalog_item_id),
        billing_interval: form.billing_interval,
        currency:         form.currency.toUpperCase(),
        unit_amount:      Math.round(parseFloat(form.unit_amount_dollars) * 100),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pricing', 'prices'] })
      setShowForm(false)
      setForm({ catalog_item_id: '', billing_interval: 'monthly', currency: 'USD', unit_amount_dollars: '' })
    },
  })

  const remove = useMutation({
    mutationFn: (id: number) => deletePrice(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pricing', 'prices'] }),
  })

  if (isLoading) return <div className="p-6 text-sm text-gray-500">Loading prices…</div>

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <p className="text-sm text-gray-500">
          Base prices in cents. Items with no price row are treated as "contact sales".
        </p>
        <button
          onClick={() => setShowForm(v => !v)}
          className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        >
          {showForm ? 'Cancel' : '+ Add Price'}
        </button>
      </div>

      {showForm && (
        <div className="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Item ID</label>
              <input
                type="number"
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.catalog_item_id}
                onChange={e => setForm(f => ({ ...f, catalog_item_id: e.target.value }))}
                placeholder="e.g. 5"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Interval</label>
              <select
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.billing_interval}
                onChange={e => setForm(f => ({ ...f, billing_interval: e.target.value as BillingInterval }))}
              >
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Currency</label>
              <input
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.currency}
                onChange={e => setForm(f => ({ ...f, currency: e.target.value }))}
                maxLength={3}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Amount ($)</label>
              <input
                type="number"
                step="0.01"
                min="0"
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.unit_amount_dollars}
                onChange={e => setForm(f => ({ ...f, unit_amount_dollars: e.target.value }))}
                placeholder="0.00"
              />
            </div>
          </div>
          <div className="flex justify-end gap-2">
            <button
              onClick={() => upsert.mutate()}
              disabled={upsert.isPending || !form.catalog_item_id || !form.unit_amount_dollars}
              className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
              {upsert.isPending ? 'Saving…' : 'Save Price'}
            </button>
          </div>
        </div>
      )}

      {prices.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-300 p-12 text-center">
          <p className="text-sm text-gray-500">No prices configured yet.</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Item</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Interval</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Currency</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Amount</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 bg-white">
              {prices.map((p: CatalogPrice) => (
                <tr key={p.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-sm font-medium text-gray-900">
                    {p.catalog_item?.name ?? `Item #${p.catalog_item_id}`}
                    <span className="ml-2 text-xs text-gray-400">{p.catalog_item?.key}</span>
                  </td>
                  <td className="px-4 py-3">
                    <Badge color={INTERVAL_COLORS[p.billing_interval]}>{p.billing_interval}</Badge>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">{p.currency}</td>
                  <td className="px-4 py-3 text-right text-sm font-semibold text-gray-900">
                    {cents(p.unit_amount)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      onClick={() => remove.mutate(p.id)}
                      className="text-xs text-red-500 hover:text-red-700"
                    >
                      Remove
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ── Price Rules Tab ───────────────────────────────────────────────────────────

const RULE_TYPES: PriceRuleType[] = [
  'percentage_discount',
  'fixed_discount',
  'yearly_discount',
  'volume_tier',
]

function PriceRulesTab() {
  const qc = useQueryClient()
  const { data: rules = [], isLoading } = useQuery({
    queryKey: ['pricing', 'rules'],
    queryFn: () => listRules(),
  })

  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    name: '',
    rule_type: 'percentage_discount' as PriceRuleType,
    modifier_value: '',
    priority: '0',
    is_exclusive: false,
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: '',
  })

  const create = useMutation({
    mutationFn: () =>
      createRule({
        name:           form.name,
        rule_type:      form.rule_type,
        modifier_value: parseFloat(form.modifier_value),
        priority:       parseInt(form.priority),
        is_exclusive:   form.is_exclusive,
        effective_from: form.effective_from,
        effective_to:   form.effective_to || null,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pricing', 'rules'] })
      setShowForm(false)
    },
  })

  const toggle = useMutation({
    mutationFn: (rule: CatalogPriceRule) =>
      updateRule(rule.id, { is_active: !rule.is_active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pricing', 'rules'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => deleteRule(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pricing', 'rules'] }),
  })

  if (isLoading) return <div className="p-6 text-sm text-gray-500">Loading rules…</div>

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <p className="text-sm text-gray-500">
          Rules are evaluated in priority order (highest first). Exclusive rules stop further evaluation.
        </p>
        <button
          onClick={() => setShowForm(v => !v)}
          className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        >
          {showForm ? 'Cancel' : '+ Add Rule'}
        </button>
      </div>

      {showForm && (
        <div className="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div className="col-span-2 sm:col-span-1">
              <label className="block text-xs font-medium text-gray-700 mb-1">Name</label>
              <input
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.name}
                onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                placeholder="e.g. Yearly Discount"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Rule Type</label>
              <select
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.rule_type}
                onChange={e => setForm(f => ({ ...f, rule_type: e.target.value as PriceRuleType }))}
              >
                {RULE_TYPES.map(t => (
                  <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">
                Modifier {['percentage_discount', 'yearly_discount', 'volume_tier'].includes(form.rule_type) ? '(%)' : '(cents)'}
              </label>
              <input
                type="number"
                step="0.01"
                min="0"
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.modifier_value}
                onChange={e => setForm(f => ({ ...f, modifier_value: e.target.value }))}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Priority</label>
              <input
                type="number"
                min="0"
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.priority}
                onChange={e => setForm(f => ({ ...f, priority: e.target.value }))}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Effective From</label>
              <input
                type="date"
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.effective_from}
                onChange={e => setForm(f => ({ ...f, effective_from: e.target.value }))}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Effective To</label>
              <input
                type="date"
                className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
                value={form.effective_to}
                onChange={e => setForm(f => ({ ...f, effective_to: e.target.value }))}
              />
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.is_exclusive}
              onChange={e => setForm(f => ({ ...f, is_exclusive: e.target.checked }))}
            />
            Exclusive (no other rules apply after this one)
          </label>
          <div className="flex justify-end">
            <button
              onClick={() => create.mutate()}
              disabled={create.isPending || !form.name || !form.modifier_value}
              className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
              {create.isPending ? 'Saving…' : 'Create Rule'}
            </button>
          </div>
        </div>
      )}

      {rules.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-300 p-12 text-center">
          <p className="text-sm text-gray-500">No pricing rules yet.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {rules.map((rule: CatalogPriceRule) => (
            <div
              key={rule.id}
              className={`rounded-xl border px-4 py-3 flex items-center gap-4 ${rule.is_active ? 'border-gray-200 bg-white' : 'border-gray-100 bg-gray-50 opacity-60'}`}
            >
              <div className="w-8 text-center text-xs font-bold text-gray-400">
                P{rule.priority}
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-medium text-sm text-gray-900">{rule.name}</span>
                  <Badge color={RULE_TYPE_COLORS[rule.rule_type]}>
                    {rule.rule_type.replace(/_/g, ' ')}
                  </Badge>
                  {rule.is_exclusive && (
                    <Badge color="bg-red-100 text-red-700">exclusive</Badge>
                  )}
                  {!rule.is_active && (
                    <Badge color="bg-gray-200 text-gray-500">inactive</Badge>
                  )}
                </div>
                <div className="mt-0.5 text-xs text-gray-500">
                  Modifier: <strong>{rule.modifier_value}</strong>
                  {rule.catalog_item_id ? ` · Item #${rule.catalog_item_id}` : ' · Global'}
                  {' · '}
                  {rule.effective_from} → {rule.effective_to ?? '∞'}
                </div>
              </div>
              <div className="flex items-center gap-3 shrink-0">
                <button
                  onClick={() => toggle.mutate(rule)}
                  className="text-xs text-gray-500 hover:text-gray-800"
                >
                  {rule.is_active ? 'Disable' : 'Enable'}
                </button>
                <button
                  onClick={() => remove.mutate(rule.id)}
                  className="text-xs text-red-500 hover:text-red-700"
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Quote Preview Tab ─────────────────────────────────────────────────────────

function QuotePreviewTab() {
  const qc = useQueryClient()
  const [tenantId, setTenantId] = useState('')
  const [interval, setInterval] = useState<BillingInterval>('monthly')
  const [currency, setCurrency] = useState('USD')
  const [snapshot, setSnapshot] = useState<TenantQuoteSnapshot | null>(null)
  const [lookupId, setLookupId] = useState('')

  const generate = useMutation({
    mutationFn: () =>
      generateQuote(tenantId, { billing_interval: interval, currency }),
    onSuccess: (data) => {
      setSnapshot(data)
      qc.invalidateQueries({ queryKey: ['pricing', 'quote', tenantId] })
    },
  })

  const { data: latestQuote } = useQuery({
    queryKey: ['pricing', 'quote', lookupId],
    queryFn: () => getLatestQuote(lookupId),
    enabled: !!lookupId,
  })

  const displaySnapshot = snapshot ?? latestQuote

  return (
    <div className="space-y-6">
      {/* Generator panel */}
      <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
        <h3 className="text-sm font-semibold text-gray-800 mb-3">Generate Quote</h3>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Tenant ID</label>
            <input
              className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm font-mono"
              value={tenantId}
              onChange={e => setTenantId(e.target.value)}
              placeholder="uuid…"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Billing Interval</label>
            <select
              className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
              value={interval}
              onChange={e => setInterval(e.target.value as BillingInterval)}
            >
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Currency</label>
            <input
              className="w-full rounded border border-gray-300 px-3 py-1.5 text-sm"
              value={currency}
              onChange={e => setCurrency(e.target.value)}
              maxLength={3}
            />
          </div>
        </div>
        <div className="mt-3 flex items-center gap-3">
          <button
            onClick={() => generate.mutate()}
            disabled={generate.isPending || !tenantId}
            className="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {generate.isPending ? 'Generating…' : 'Generate Quote'}
          </button>
          {generate.isError && (
            <span className="text-xs text-red-600">Failed — check tenant ID and entitlements</span>
          )}
        </div>
      </div>

      {/* Lookup panel */}
      <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
        <h3 className="text-sm font-semibold text-gray-800 mb-3">Look Up Latest Quote</h3>
        <div className="flex gap-3">
          <input
            className="flex-1 rounded border border-gray-300 px-3 py-1.5 text-sm font-mono"
            value={lookupId}
            onChange={e => setLookupId(e.target.value)}
            placeholder="Tenant ID…"
          />
        </div>
      </div>

      {/* Snapshot display */}
      {displaySnapshot && (
        <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
          {/* Header */}
          <div className="border-b border-gray-100 px-5 py-4 flex items-start justify-between">
            <div>
              <div className="text-xs text-gray-400 font-mono">{displaySnapshot.tenant_id}</div>
              <div className="mt-1 flex items-center gap-2">
                <Badge color={INTERVAL_COLORS[displaySnapshot.billing_interval]}>
                  {displaySnapshot.billing_interval}
                </Badge>
                <span className="text-xs text-gray-500">{displaySnapshot.currency}</span>
                <span className="text-xs text-gray-400">
                  Generated {new Date(displaySnapshot.generated_at).toLocaleString()}
                </span>
              </div>
            </div>
            <div className="text-right">
              <div className="text-xs text-gray-400">Total</div>
              <div className="text-2xl font-bold text-gray-900">{cents(displaySnapshot.total_cents)}</div>
              {displaySnapshot.discount_cents > 0 && (
                <div className="text-xs text-green-600">
                  −{cents(displaySnapshot.discount_cents)} discount
                </div>
              )}
            </div>
          </div>

          {/* Line items */}
          <table className="min-w-full divide-y divide-gray-100">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Item</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Source</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Rules Applied</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Unit</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Discount</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {displaySnapshot.line_items.map((li, i) => (
                <tr key={i} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5">
                    <div className="text-sm font-medium text-gray-900">{li.item_name}</div>
                    <div className="text-xs text-gray-400 font-mono">{li.item_key}</div>
                  </td>
                  <td className="px-4 py-2.5">
                    <Badge color="bg-gray-100 text-gray-700">
                      {li.source.replace(/_/g, ' ')}
                    </Badge>
                  </td>
                  <td className="px-4 py-2.5 text-xs text-gray-500">
                    {li.rules_applied.length > 0
                      ? li.rules_applied.join(', ')
                      : <span className="text-gray-300">—</span>}
                  </td>
                  <td className="px-4 py-2.5 text-right text-sm text-gray-600">{cents(li.unit_amount)}</td>
                  <td className="px-4 py-2.5 text-right text-sm text-green-600">
                    {li.discount_cents > 0 ? `−${cents(li.discount_cents)}` : '—'}
                  </td>
                  <td className="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">
                    {cents(li.line_total_cents)}
                  </td>
                </tr>
              ))}
            </tbody>
            {/* Footer */}
            <tfoot className="bg-gray-50">
              <tr>
                <td colSpan={4} className="px-4 py-3 text-right text-xs font-medium text-gray-500">
                  Subtotal
                </td>
                <td />
                <td className="px-4 py-3 text-right text-sm font-semibold text-gray-700">
                  {cents(displaySnapshot.subtotal_cents)}
                </td>
              </tr>
              <tr>
                <td colSpan={4} className="px-4 py-3 text-right text-xs font-medium text-green-600">
                  Total Discount
                </td>
                <td />
                <td className="px-4 py-3 text-right text-sm font-semibold text-green-600">
                  −{cents(displaySnapshot.discount_cents)}
                </td>
              </tr>
              <tr>
                <td colSpan={4} className="px-4 py-3 text-right text-sm font-bold text-gray-800">
                  Total Due ({displaySnapshot.billing_interval})
                </td>
                <td />
                <td className="px-4 py-3 text-right text-lg font-bold text-gray-900">
                  {cents(displaySnapshot.total_cents)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  )
}

// ── Main Page ─────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'prices', label: 'Price Catalog' },
  { id: 'rules',  label: 'Price Rules' },
  { id: 'quote',  label: 'Quote Preview' },
] as const

type TabId = (typeof TABS)[number]['id']

export default function PricingCalculatorPage() {
  const [activeTab, setActiveTab] = useState<TabId>('prices')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Pricing Engine</h1>
        <p className="mt-1 text-sm text-gray-500">
          Manage base prices, discount rules, and generate tenant-specific quotes.
        </p>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-6">
          {TABS.map(t => (
            <button
              key={t.id}
              onClick={() => setActiveTab(t.id)}
              className={`whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors ${
                activeTab === t.id
                  ? 'border-brand-600 text-brand-600'
                  : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab content */}
      {activeTab === 'prices' && <PriceCatalogTab />}
      {activeTab === 'rules'  && <PriceRulesTab />}
      {activeTab === 'quote'  && <QuotePreviewTab />}
    </div>
  )
}
