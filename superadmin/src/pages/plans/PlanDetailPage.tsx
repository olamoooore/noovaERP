import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { getPlanFamily } from '@/lib/admin-api/plans'
import type { PlanFamily, PlanVersion, PlanPrice } from '@/types/admin'
import { cn } from '@/lib/utils'

function formatPrice(price: PlanPrice): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: price.currency,
    minimumFractionDigits: 2,
  }).format(price.unit_amount / 100)
}

function VersionCard({ version }: { version: PlanVersion }) {
  const modules = version.plan_modules ?? []

  return (
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
      <div className="flex items-start justify-between">
        <div>
          <span className="font-semibold text-gray-900">{version.version_label}</span>
          {version.description && (
            <p className="mt-0.5 text-sm text-gray-500">{version.description}</p>
          )}
        </div>
        <div className="flex gap-2">
          <span className={cn(
            'rounded-full px-2 py-0.5 text-xs font-medium',
            version.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500',
          )}>
            {version.is_active ? 'Active' : 'Inactive'}
          </span>
          {version.is_publicly_listed && (
            <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
              Listed
            </span>
          )}
        </div>
      </div>

      {/* Prices */}
      {version.prices && version.prices.length > 0 && (
        <div className="mt-4 flex flex-wrap gap-3">
          {version.prices.map((p) => (
            <div key={p.id} className="rounded-lg border border-gray-200 px-3 py-2 text-center">
              <p className="text-lg font-bold text-gray-900">{formatPrice(p)}</p>
              <p className="text-xs text-gray-400 capitalize">{p.billing_interval}</p>
            </div>
          ))}
        </div>
      )}

      {/* Modules */}
      {modules.length > 0 && (
        <div className="mt-4">
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Included Modules</p>
          <div className="flex flex-wrap gap-1.5">
            {modules.map((pm) => (
              <span key={pm.id} className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700">
                {pm.module?.module_key ?? pm.module_catalog_id}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

export function PlanDetailPage() {
  const { familyId } = useParams<{ familyId: string }>()

  const { data: family, isLoading, isError } = useQuery<PlanFamily>({
    queryKey: ['admin', 'plans', familyId],
    queryFn: () => getPlanFamily(Number(familyId)),
    enabled: !!familyId,
  })

  if (isLoading) return <p className="text-sm text-gray-400">Loading plan…</p>
  if (isError || !family) return <p className="text-sm text-red-500">Failed to load plan.</p>

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <nav className="flex items-center gap-1.5 text-sm text-gray-500">
        <Link to="/plans" className="hover:text-brand-600">Plans</Link>
        <Icon icon="solar:alt-arrow-right-bold-duotone" width={14} />
        <span className="font-medium text-gray-900">{family.name}</span>
      </nav>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{family.name}</h1>
          <p className="mt-0.5 font-mono text-sm text-gray-400">{family.key}</p>
          {family.description && <p className="mt-1 text-sm text-gray-600">{family.description}</p>}
        </div>
        <span className={cn(
          'rounded-full px-3 py-1 text-sm font-medium',
          family.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500',
        )}>
          {family.is_active ? 'Active' : 'Inactive'}
        </span>
      </div>

      {/* Versions */}
      <div>
        <h2 className="mb-3 text-lg font-semibold text-gray-800">Versions</h2>
        {(!family.versions || family.versions.length === 0) ? (
          <div className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-400 text-sm">
            No versions yet.
          </div>
        ) : (
          <div className="space-y-4">
            {family.versions.map((v) => <VersionCard key={v.id} version={v} />)}
          </div>
        )}
      </div>
    </div>
  )
}
