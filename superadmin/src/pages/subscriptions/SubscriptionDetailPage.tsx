import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getSubscription, cancelSubscription, reactivateSubscription } from '@/lib/admin-api/subscriptions'
import { markInvoicePaid, voidInvoice, generateInvoice } from '@/lib/admin-api/billing'
import { getPlanFamilies } from '@/lib/admin-api/plans'
import { runTenantOperation } from '@/lib/admin-api/tenants'
import { useAuthStore } from '@/lib/store/auth'
import type { SubscriptionStatus, InvoiceStatus, SubscriptionInvoice } from '@/types/admin'

const SUB_STATUS_STYLES: Record<SubscriptionStatus, string> = {
  active:   'bg-green-100 text-green-800',
  trialing: 'bg-blue-100 text-blue-700',
  past_due: 'bg-orange-100 text-orange-800',
  canceled: 'bg-gray-100 text-gray-600',
  expired:  'bg-red-100 text-red-700',
}

const INV_STATUS_STYLES: Record<InvoiceStatus, string> = {
  draft:          'bg-gray-100 text-gray-600',
  open:           'bg-yellow-100 text-yellow-800',
  paid:           'bg-green-100 text-green-800',
  void:           'bg-gray-200 text-gray-500',
  uncollectible:  'bg-red-100 text-red-700',
}

function formatDate(d: string | null | undefined) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatMoney(cents: number, currency = 'USD') {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100)
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex justify-between py-2 border-b border-gray-100 last:border-0">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-sm text-gray-900 font-medium">{value}</span>
    </div>
  )
}

function InvoiceRow({ invoice, onMarkPaid, onVoid, readonly }: {
  invoice: SubscriptionInvoice
  onMarkPaid: (id: number) => void
  onVoid: (id: number) => void
  readonly: boolean
}) {
  return (
    <tr className="hover:bg-gray-50">
      <td className="px-4 py-3 text-xs font-mono text-gray-700">{invoice.invoice_number}</td>
      <td className="px-4 py-3 text-gray-600 capitalize">{invoice.billing_interval}</td>
      <td className="px-4 py-3 text-gray-600">{formatDate(invoice.period_start)} → {formatDate(invoice.period_end)}</td>
      <td className="px-4 py-3 font-medium text-gray-900">{formatMoney(invoice.total, invoice.currency)}</td>
      <td className="px-4 py-3">
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${INV_STATUS_STYLES[invoice.status] ?? 'bg-gray-100 text-gray-700'}`}>
          {invoice.status}
        </span>
      </td>
      <td className="px-4 py-3 text-gray-500">{formatDate(invoice.due_date)}</td>
      <td className="px-4 py-3 text-gray-500">{formatDate(invoice.paid_at)}</td>
      {!readonly && (
        <td className="px-4 py-3">
          <div className="flex gap-2">
            {invoice.status === 'open' && (
              <button
                onClick={() => onMarkPaid(invoice.id)}
                className="text-xs text-green-700 hover:underline"
              >
                Mark Paid
              </button>
            )}
            {(invoice.status === 'open' || invoice.status === 'draft') && (
              <button
                onClick={() => onVoid(invoice.id)}
                className="text-xs text-red-600 hover:underline"
              >
                Void
              </button>
            )}
          </div>
        </td>
      )}
    </tr>
  )
}

export default function SubscriptionDetailPage() {
  const { subscriptionId } = useParams<{ subscriptionId: string }>()

  const queryClient = useQueryClient()
  const { user } = useAuthStore()
  const isReadonly = user?.role === 'support_readonly'

  const [cancelReason, setCancelReason]           = useState('')
  const [showCancelForm, setShowCancelForm]       = useState(false)
  const [generateInterval, setGenerateInterval]   = useState<'monthly' | 'yearly'>('monthly')
  const [showMigratePlan, setShowMigratePlan]     = useState(false)
  const [migratePlanVersionId, setMigratePlanVersionId] = useState<number | ''>('')
  const [migrateInterval, setMigrateInterval]     = useState<'monthly' | 'yearly'>('monthly')
  const [migrateReason, setMigrateReason]         = useState('')
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'subscriptions', subscriptionId],
    queryFn: () => getSubscription(Number(subscriptionId)),
    enabled: !!subscriptionId,
  })

  const sub = data?.data
  const invoices = sub?.invoices ?? []

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'subscriptions', subscriptionId] })

  const cancelMutation = useMutation({
    mutationFn: () => cancelSubscription(Number(subscriptionId), cancelReason || undefined),
    onSuccess: () => { invalidate(); setShowCancelForm(false); setCancelReason('') },
    onError: () => setError('Failed to cancel subscription.'),
  })

  const reactivateMutation = useMutation({
    mutationFn: () => reactivateSubscription(Number(subscriptionId)),
    onSuccess: invalidate,
    onError: () => setError('Failed to reactivate subscription.'),
  })

  const markPaidMutation = useMutation({
    mutationFn: (invoiceId: number) => markInvoicePaid(invoiceId),
    onSuccess: invalidate,
    onError: () => setError('Failed to mark invoice as paid.'),
  })

  const voidMutation = useMutation({
    mutationFn: (invoiceId: number) => voidInvoice(invoiceId),
    onSuccess: invalidate,
    onError: () => setError('Failed to void invoice.'),
  })

  const generateMutation = useMutation({
    mutationFn: () => generateInvoice(Number(subscriptionId), generateInterval),
    onSuccess: invalidate,
    onError: () => setError('Failed to generate invoice.'),
  })

  const { data: planFamilies, isLoading: plansLoading } = useQuery({
    queryKey: ['admin', 'plans', 'families-with-versions'],
    queryFn: getPlanFamilies,
    enabled: showMigratePlan,
  })

  const migrateMutation = useMutation({
    mutationFn: () => runTenantOperation(
      sub!.tenant_id,
      'migrate_plan',
      { plan_version_id: migratePlanVersionId, billing_interval: migrateInterval },
      migrateReason || undefined,
    ),
    onSuccess: () => {
      invalidate()
      setShowMigratePlan(false)
      setMigratePlanVersionId('')
      setMigrateReason('')
    },
    onError: () => setError('Failed to migrate plan.'),
  })

  if (isLoading) return <div className="p-6 text-gray-400">Loading…</div>
  if (isError || !sub) return <div className="p-6 text-red-500">Failed to load subscription.</div>

  const isCancelable = ['active', 'trialing', 'past_due'].includes(sub.status) && !sub.canceled_at
  const isReactivatable = sub.canceled_at !== null && sub.status !== 'expired'

  return (
    <div className="p-6 space-y-6">
      {/* Breadcrumb */}
      <div className="flex items-center gap-2 text-sm text-gray-500">
        <Link to="/subscriptions" className="hover:text-brand-600">Subscriptions</Link>
        <span>/</span>
        <span className="text-gray-900 font-medium">#{sub.id}</span>
      </div>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {sub.plan_version?.family?.name ?? 'Subscription'}{' '}
            <span className="text-gray-400 font-normal text-lg">{sub.plan_version?.version_label}</span>
          </h1>
          <p className="text-sm text-gray-500 mt-1 font-mono">{sub.tenant_id}</p>
        </div>
        <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${SUB_STATUS_STYLES[sub.status] ?? 'bg-gray-100 text-gray-700'}`}>
          {sub.status.replace('_', ' ')}
        </span>
      </div>

      {error && (
        <div className="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Details */}
        <div className="bg-white rounded-xl border border-gray-200 p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-3">Subscription Details</h2>
          <InfoRow label="Plan" value={`${sub.plan_version?.family?.name ?? '—'} ${sub.plan_version?.version_label ?? ''}`} />
          <InfoRow label="Current Period" value={`${formatDate(sub.current_period_start)} → ${formatDate(sub.current_period_end)}`} />
          <InfoRow label="Trial Ends" value={formatDate(sub.trial_ends_at)} />
          <InfoRow label="Canceled At" value={sub.canceled_at ? formatDate(sub.canceled_at) : '—'} />
          {sub.cancellation_reason && (
            <InfoRow label="Cancel Reason" value={sub.cancellation_reason} />
          )}
          <InfoRow label="Created" value={formatDate(sub.created_at)} />
        </div>

        {/* Actions */}
        {!isReadonly && (
          <div className="bg-white rounded-xl border border-gray-200 p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-3">Actions</h2>
            <div className="space-y-3">
              {isCancelable && !showCancelForm && (
                <button
                  onClick={() => setShowCancelForm(true)}
                  className="w-full text-left px-4 py-2.5 rounded-lg border border-red-200 text-sm text-red-700 hover:bg-red-50 transition-colors"
                >
                  Cancel Subscription
                </button>
              )}

              {showCancelForm && (
                <div className="space-y-2">
                  <textarea
                    value={cancelReason}
                    onChange={e => setCancelReason(e.target.value)}
                    placeholder="Reason for cancellation (optional)…"
                    rows={3}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                  />
                  <div className="flex gap-2">
                    <button
                      onClick={() => cancelMutation.mutate()}
                      disabled={cancelMutation.isPending}
                      className="flex-1 bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50"
                    >
                      {cancelMutation.isPending ? 'Canceling…' : 'Confirm Cancel'}
                    </button>
                    <button
                      onClick={() => setShowCancelForm(false)}
                      className="px-4 py-2 rounded-lg text-sm border border-gray-300 hover:bg-gray-50"
                    >
                      Dismiss
                    </button>
                  </div>
                </div>
              )}

              {isReactivatable && (
                <button
                  onClick={() => reactivateMutation.mutate()}
                  disabled={reactivateMutation.isPending}
                  className="w-full text-left px-4 py-2.5 rounded-lg border border-green-200 text-sm text-green-700 hover:bg-green-50 transition-colors disabled:opacity-50"
                >
                  {reactivateMutation.isPending ? 'Reactivating…' : 'Reactivate Subscription'}
                </button>
              )}

              {/* Change Plan */}
              {!showMigratePlan ? (
                <button
                  onClick={() => setShowMigratePlan(true)}
                  className="w-full text-left px-4 py-2.5 rounded-lg border border-brand-200 text-sm text-brand-700 hover:bg-brand-50 transition-colors"
                >
                  Change Plan
                </button>
              ) : (
                <div className="space-y-2 border border-brand-200 rounded-lg p-3">
                  <p className="text-xs font-medium text-gray-700">Change Plan</p>

                  <div>
                    <label className="block text-xs text-gray-500 mb-1">New Plan Version <span className="text-red-500">*</span></label>
                    {plansLoading ? (
                      <p className="text-xs text-gray-400">Loading plans…</p>
                    ) : (
                      <select
                        value={migratePlanVersionId}
                        onChange={e => setMigratePlanVersionId(Number(e.target.value))}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                      >
                        <option value="">Select a plan version…</option>
                        {planFamilies?.map(family =>
                          family.versions?.filter(v => v.is_active).map(version => (
                            <option key={version.id} value={version.id}>
                              {family.name} — {version.version_label}
                            </option>
                          ))
                        )}
                      </select>
                    )}
                  </div>

                  <div>
                    <label className="block text-xs text-gray-500 mb-1">Billing Interval</label>
                    <select
                      value={migrateInterval}
                      onChange={e => setMigrateInterval(e.target.value as 'monthly' | 'yearly')}
                      className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                      <option value="monthly">Monthly</option>
                      <option value="yearly">Yearly</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs text-gray-500 mb-1">Reason (optional)</label>
                    <textarea
                      value={migrateReason}
                      onChange={e => setMigrateReason(e.target.value)}
                      rows={2}
                      placeholder="Reason for plan change…"
                      className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    />
                  </div>

                  <div className="flex gap-2">
                    <button
                      onClick={() => migrateMutation.mutate()}
                      disabled={!migratePlanVersionId || migrateMutation.isPending}
                      className="flex-1 bg-brand-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-700 disabled:opacity-50"
                    >
                      {migrateMutation.isPending ? 'Changing…' : 'Confirm Change'}
                    </button>
                    <button
                      onClick={() => setShowMigratePlan(false)}
                      className="px-4 py-2 rounded-lg text-sm border border-gray-300 hover:bg-gray-50"
                    >
                      Dismiss
                    </button>
                  </div>
                </div>
              )}

              <div className="space-y-2">
                <label className="block text-xs text-gray-500 font-medium">Generate Invoice</label>
                <div className="flex gap-2">
                  <select
                    value={generateInterval}
                    onChange={e => setGenerateInterval(e.target.value as 'monthly' | 'yearly')}
                    className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                  >
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                  </select>
                  <button
                    onClick={() => generateMutation.mutate()}
                    disabled={generateMutation.isPending}
                    className="px-4 py-2 rounded-lg border border-gray-200 text-sm text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50 whitespace-nowrap"
                  >
                    {generateMutation.isPending ? 'Generating…' : 'Generate'}
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Invoices */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-700">Invoices ({invoices.length})</h2>
        </div>
        {invoices.length === 0 ? (
          <div className="p-8 text-center text-gray-400 text-sm">No invoices yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 font-medium text-gray-600">Invoice #</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Interval</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Period</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Total</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Status</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Due</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Paid</th>
                  {!isReadonly && <th className="px-4 py-3 font-medium text-gray-600">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {invoices.map(inv => (
                  <InvoiceRow
                    key={inv.id}
                    invoice={inv}
                    onMarkPaid={id => markPaidMutation.mutate(id)}
                    onVoid={id => voidMutation.mutate(id)}
                    readonly={isReadonly}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
