import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { startBulkHealthCheck, getBatchStatus, type BatchStatus } from '@/lib/admin-api/analytics'

type ResultRow = NonNullable<BatchStatus['results']>[number]

export default function OperationsPage() {
  const [batchId, setBatchId] = useState<string | null>(null)
  const [status, setStatus]   = useState<BatchStatus | null>(null)
  const pollRef               = useRef<ReturnType<typeof setInterval> | null>(null)

  const stopPolling = () => {
    if (pollRef.current) {
      clearInterval(pollRef.current)
      pollRef.current = null
    }
  }

  const pollStatus = async (id: string) => {
    try {
      const s = await getBatchStatus(id)
      setStatus(s)
      if (s.status === 'done' || s.status === 'failed') stopPolling()
    } catch {
      stopPolling()
    }
  }

  const startMutation = useMutation({
    mutationFn: () => startBulkHealthCheck(),
    onSuccess: (data) => {
      setBatchId(data.batch_id)
      setStatus({ status: 'queued', total: data.total, processed: 0, results: [] })
      pollRef.current = setInterval(() => pollStatus(data.batch_id), 2000)
    },
  })

  useEffect(() => () => stopPolling(), [])

  const total     = status?.total ?? 0
  const processed = status?.processed ?? 0
  const progress  = total > 0 ? Math.round((processed / total) * 100) : 0
  const results   = status?.results ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">Bulk Operations</h1>
        <p className="text-sm text-gray-500 mt-1">Run platform-wide health checks on all tenant schemas.</p>
      </div>

      {/* Trigger */}
      <div className="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
        <h2 className="text-sm font-semibold text-gray-700">Bulk Health Check</h2>
        <p className="text-sm text-gray-500">
          Pings every tenant schema: verifies database connectivity and reads storage usage.
          Runs in the background queue — progress updates every 2 seconds.
        </p>
        <button
          onClick={() => {
            stopPolling()
            setBatchId(null)
            setStatus(null)
            startMutation.mutate()
          }}
          disabled={startMutation.isPending || status?.status === 'running'}
          className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 disabled:opacity-40"
        >
          {startMutation.isPending ? 'Dispatching…' : status?.status === 'running' ? 'Running…' : 'Start Bulk Health Check'}
        </button>
      </div>

      {/* Status + observability */}
      {status && (
        <div className="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
          {/* Header row */}
          <div className="flex items-center justify-between flex-wrap gap-2">
            <div>
              <h2 className="text-sm font-semibold text-gray-700">Batch Progress</h2>
              {batchId && (
                <code className="text-xs text-gray-400">{batchId}</code>
              )}
            </div>
            <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${
              status.status === 'done'    ? 'bg-green-100 text-green-800' :
              status.status === 'failed'  ? 'bg-red-100 text-red-800' :
              status.status === 'running' ? 'bg-blue-100 text-blue-800' :
              'bg-gray-100 text-gray-700'
            }`}>
              {status.status.toUpperCase()}
            </span>
          </div>

          {/* Observability metrics */}
          {(status.status === 'running' || status.status === 'done') && (
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
              <div>
                <p className="text-xl font-bold text-gray-900">{total}</p>
                <p className="text-xs text-gray-500">Total</p>
              </div>
              <div>
                <p className="text-xl font-bold text-gray-900">{processed}</p>
                <p className="text-xs text-gray-500">Processed</p>
              </div>
              <div>
                <p className="text-xl font-bold text-green-700">{status.success_count ?? 0}</p>
                <p className="text-xs text-gray-500">Healthy</p>
              </div>
              <div>
                <p className="text-xl font-bold text-red-600">{status.failure_count ?? 0}</p>
                <p className="text-xs text-gray-500">Errors</p>
              </div>
            </div>
          )}

          {/* Timing */}
          {status.started_at && (
            <div className="flex gap-6 text-xs text-gray-500">
              <span>Started: {new Date(status.started_at).toLocaleTimeString()}</span>
              {status.completed_at && (
                <span>Completed: {new Date(status.completed_at).toLocaleTimeString()}</span>
              )}
            </div>
          )}

          {/* Progress bar */}
          {(status.status === 'running' || status.status === 'done') && (
            <div>
              <div className="flex justify-between text-xs text-gray-500 mb-1">
                <span>{processed} / {total} tenants</span>
                <span>{progress}%</span>
              </div>
              <div className="w-full bg-gray-100 rounded-full h-2">
                <div
                  className="bg-brand-600 h-2 rounded-full transition-all duration-500"
                  style={{ width: `${progress}%` }}
                />
              </div>
            </div>
          )}

          {/* Results table */}
          {status.status === 'done' && results.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="bg-gray-50 text-left text-gray-500 uppercase tracking-wide">
                  <tr>
                    <th className="px-4 py-2">Tenant</th>
                    <th className="px-4 py-2">DB Connected</th>
                    <th className="px-4 py-2">Storage (MB)</th>
                    <th className="px-4 py-2">Result</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {results.map((r: ResultRow) => (
                    <tr key={r.tenant_id} className={r.status === 'error' ? 'bg-red-50' : ''}>
                      <td className="px-4 py-2 font-medium text-gray-800">{r.tenant_id}</td>
                      <td className="px-4 py-2">{r.status === 'error' ? '—' : r.db_connected ? '✓' : '✗'}</td>
                      <td className="px-4 py-2">{r.storage_mb ?? '—'}</td>
                      <td className="px-4 py-2">
                        {r.status === 'error'
                          ? <span className="text-red-600">{r.error}</span>
                          : <span className="text-green-700">ok</span>
                        }
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {status.status === 'failed' && (
            <p className="text-sm text-red-600">{status.error ?? 'Job failed.'}</p>
          )}
        </div>
      )}
    </div>
  )
}
