import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { getModules, syncModules } from '@/lib/admin-api/modules'
import type { ModuleCatalogEntry } from '@/types/admin'
import { cn } from '@/lib/utils'

function TypeBadge({ type }: { type: ModuleCatalogEntry['type'] }) {
  return (
    <span className={cn(
      'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
      type === 'core' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700',
    )}>
      {type}
    </span>
  )
}

function ActiveBadge({ active }: { active: boolean }) {
  return (
    <span className={cn(
      'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
      active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400',
    )}>
      {active ? 'Active' : 'Inactive'}
    </span>
  )
}

export function ModuleCatalogPage() {
  const queryClient = useQueryClient()

  const { data: modules = [], isLoading, isError } = useQuery<ModuleCatalogEntry[]>({
    queryKey: ['admin', 'modules'],
    queryFn: getModules,
  })

  const { mutate: triggerSync, isPending: isSyncing } = useMutation({
    mutationFn: syncModules,
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'modules'] })
      // Simple browser alert for now — toast system can be wired in Phase 2
      alert(`Synced ${result.synced} modules from manifests.`)
    },
    onError: () => {
      alert('Sync failed. Check that the backend is running.')
    },
  })

  const coreModules  = modules.filter(m => m.type === 'core')
  const addonModules = modules.filter(m => m.type === 'addon')

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Module Catalog</h1>
          <p className="mt-1 text-sm text-gray-500">
            {modules.length} modules registered — sourced from module.json manifests.
          </p>
        </div>
        <button
          onClick={() => triggerSync()}
          disabled={isSyncing}
          className="flex items-center gap-2 rounded-lg border border-brand-300 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-100 disabled:opacity-50 transition-colors"
        >
          <Icon
            icon="solar:refresh-bold-duotone"
            width={16}
            className={isSyncing ? 'animate-spin' : ''}
          />
          {isSyncing ? 'Syncing…' : 'Sync from Manifests'}
        </button>
      </div>

      {isLoading && <p className="text-sm text-gray-400">Loading modules…</p>}
      {isError   && <p className="text-sm text-red-500">Failed to load module catalog.</p>}

      {!isLoading && !isError && (
        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Key</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Type</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Plans</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Sort</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {/* Core modules */}
              {coreModules.length > 0 && (
                <tr>
                  <td colSpan={6} className="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                    Core Modules ({coreModules.length})
                  </td>
                </tr>
              )}
              {coreModules.map((m) => <ModuleRow key={m.id} module={m} />)}

              {/* Addon modules */}
              {addonModules.length > 0 && (
                <tr>
                  <td colSpan={6} className="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                    Add-on Modules ({addonModules.length})
                  </td>
                </tr>
              )}
              {addonModules.map((m) => <ModuleRow key={m.id} module={m} />)}

              {modules.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-gray-400">
                    No modules yet. Click "Sync from Manifests" to populate.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function ModuleRow({ module: m }: { module: ModuleCatalogEntry }) {
  return (
    <tr className="hover:bg-gray-50 transition-colors">
      <td className="px-4 py-3 font-mono text-xs text-gray-600">{m.module_key}</td>
      <td className="px-4 py-3 font-medium text-gray-900">{m.name}</td>
      <td className="px-4 py-3"><TypeBadge type={m.type} /></td>
      <td className="px-4 py-3"><ActiveBadge active={m.is_active} /></td>
      <td className="px-4 py-3 text-gray-500">{m.plan_modules_count ?? 0}</td>
      <td className="px-4 py-3 text-gray-400">{m.sort_order}</td>
    </tr>
  )
}
