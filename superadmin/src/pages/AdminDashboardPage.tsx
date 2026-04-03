import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { adminApi } from '@/lib/admin-api/client'

interface DashboardSummary {
  status: string
  version: string
  tenant_count: number
  phase: string
  message: string
}

function StatCard({ icon, label, value, color }: { icon: string; label: string; value: string | number; color: string }) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
      <div className="flex items-start justify-between">
        <div>
          <p className="text-sm text-gray-500">{label}</p>
          <p className="mt-1 text-2xl font-bold text-gray-900">{value}</p>
        </div>
        <div className={`rounded-lg p-2.5 ${color}`}>
          <Icon icon={icon} width={22} />
        </div>
      </div>
    </div>
  )
}

export function AdminDashboardPage() {
  const { data, isLoading, isError } = useQuery<DashboardSummary>({
    queryKey: ['admin', 'dashboard'],
    queryFn: async () => {
      const { data } = await adminApi.get<DashboardSummary>('/dashboard')
      return data
    },
    retry: 1,
  })

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Control Plane</h1>
        <p className="mt-1 text-sm text-gray-500">
          Platform-level overview. Tenant ERP data is not shown here.
        </p>
      </div>

      {/* Status banner */}
      {isLoading && (
        <div className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
          <Icon icon="solar:refresh-bold-duotone" className="animate-spin" width={16} />
          Loading system status…
        </div>
      )}

      {isError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <Icon icon="solar:danger-circle-bold-duotone" width={16} />
          Could not reach the backend. Check that the Laravel server is running.
        </div>
      )}

      {data && (
        <>
          {/* Stats */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard
              icon="solar:buildings-bold-duotone"
              label="Total Tenants"
              value={data.tenant_count}
              color="bg-brand-50 text-brand-600"
            />
            <StatCard
              icon="solar:server-bold-duotone"
              label="System Status"
              value={data.status === 'ok' ? 'Healthy' : data.status}
              color="bg-green-50 text-green-600"
            />
            <StatCard
              icon="solar:code-square-bold-duotone"
              label="Version"
              value={data.version}
              color="bg-purple-50 text-purple-600"
            />
          </div>

          {/* Phase notice */}
          <div className="rounded-xl border border-dashed border-brand-300 bg-brand-50 p-6">
            <div className="flex items-start gap-4">
              <div className="rounded-lg bg-brand-100 p-2.5">
                <Icon icon="solar:rocket-bold-duotone" className="text-brand-700" width={24} />
              </div>
              <div>
                <h2 className="font-semibold text-brand-900">{data.phase}</h2>
                <p className="mt-1 text-sm text-brand-700">{data.message}</p>
                <ul className="mt-3 space-y-1 text-sm text-brand-600">
                  <li className="flex items-center gap-1.5">
                    <Icon icon="solar:check-circle-bold-duotone" width={14} />
                    Phase 0 complete — auth, routing, layout shell
                  </li>
                  <li className="flex items-center gap-1.5 opacity-50">
                    <Icon icon="solar:clock-circle-bold-duotone" width={14} />
                    Phase 1 — Plan catalog &amp; module catalog migrations
                  </li>
                  <li className="flex items-center gap-1.5 opacity-50">
                    <Icon icon="solar:clock-circle-bold-duotone" width={14} />
                    Phase 2 — Tenant 360 &amp; operations
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
