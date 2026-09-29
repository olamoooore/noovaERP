import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { getTaxProvenanceReport, type ProvenanceVerdict, type TenantProvenance } from '@/lib/admin-api/tax-provenance'

const VERDICT_STYLE: Record<ProvenanceVerdict, { label: string; cls: string }> = {
  synthetic_suspected: { label: 'Synthetic — do not file', cls: 'bg-red-50 text-red-700 border-red-200' },
  dormant:             { label: 'Dormant',                  cls: 'bg-amber-50 text-amber-700 border-amber-200' },
  review:              { label: 'Review',                   cls: 'bg-yellow-50 text-yellow-700 border-yellow-200' },
  active:              { label: 'Live pattern',             cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  no_tax_activity:     { label: 'No tax trail',             cls: 'bg-gray-50 text-gray-500 border-gray-200' },
  not_migrated:        { label: 'Not migrated',             cls: 'bg-gray-50 text-gray-500 border-gray-200' },
}

function VerdictBadge({ verdict }: { verdict: ProvenanceVerdict }) {
  const s = VERDICT_STYLE[verdict] ?? VERDICT_STYLE.review
  return (
    <span className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${s.cls}`}>
      <Icon icon={verdict === 'synthetic_suspected' ? 'solar:danger-triangle-bold-duotone' : 'solar:shield-check-minimalistic-bold-duotone'} width={12} />
      {s.label}
    </span>
  )
}

export default function TaxProvenancePage() {
  const { data, isFetching, error, refetch } = useQuery({
    queryKey: ['tax-provenance'],
    queryFn: getTaxProvenanceReport,
  })

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Tax Data Provenance</h1>
          <p className="mt-1 max-w-2xl text-sm text-gray-500">
            Platform honesty control: flags tenants whose statutory tax trail shows synthetic or
            compressed-creation patterns, so GST F5 / SST-02 figures are never mistaken for live
            filings. Heuristic classification — reasons are always shown; this informs, it does not certify.
          </p>
        </div>
        <button
          onClick={() => refetch()}
          disabled={isFetching}
          className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          <Icon icon={isFetching ? 'solar:refresh-circle-bold-duotone' : 'solar:refresh-bold-duotone'} className={isFetching ? 'animate-spin' : ''} width={16} />
          {isFetching ? 'Scanning…' : 'Rescan'}
        </button>
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          {[
            { label: 'Tenants', value: data.summary.tenants, tone: 'text-gray-900' },
            { label: 'Synthetic-suspected', value: data.summary.synthetic_suspected, tone: 'text-red-600' },
            { label: 'Dormant', value: data.summary.dormant, tone: 'text-amber-600' },
            { label: 'Live pattern', value: data.summary.active, tone: 'text-emerald-600' },
            { label: 'No trail', value: data.summary.no_trail, tone: 'text-gray-400' },
          ].map((s) => (
            <div key={s.label} className="rounded-xl border border-gray-200 bg-white p-4">
              <p className="text-xs text-gray-500">{s.label}</p>
              <p className={`mt-1 text-2xl font-bold ${s.tone}`}>{s.value}</p>
            </div>
          ))}
        </div>
      )}

      {error && (
        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          Failed to load provenance report. Check admin session / reports access.
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-4 py-3">Tenant</th>
              <th className="px-4 py-3">Verdict</th>
              <th className="px-4 py-3 text-right">Trail rows</th>
              <th className="px-4 py-3 text-right">Max rows / min</th>
              <th className="px-4 py-3 text-right">Authors</th>
              <th className="px-4 py-3 text-right">Periods</th>
              <th className="px-4 py-3 text-right">Creation span</th>
              <th className="px-4 py-3">Signals</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {(data?.tenants ?? []).map((t: TenantProvenance) => (
              <tr key={t.tenant_id} className={t.verdict === 'synthetic_suspected' ? 'bg-red-50/50' : ''}>
                <td className="px-4 py-3">
                  <p className="font-semibold text-gray-900">{t.name}</p>
                  <p className="font-mono text-[10px] text-gray-400">{t.tenant_id}</p>
                </td>
                <td className="px-4 py-3"><VerdictBadge verdict={t.verdict} /></td>
                <td className="px-4 py-3 text-right tabular-nums text-gray-700">{t.metrics?.rows ?? '—'}</td>
                <td className="px-4 py-3 text-right tabular-nums text-gray-700">{t.metrics?.max_burst_per_minute ?? '—'}</td>
                <td className="px-4 py-3 text-right tabular-nums text-gray-700">{t.metrics?.creators ?? '—'}</td>
                <td className="px-4 py-3 text-right tabular-nums text-gray-700">{t.metrics?.distinct_periods ?? '—'}</td>
                <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                  {t.metrics ? `${t.metrics.span_days}d` : '—'}
                </td>
                <td className="max-w-xs px-4 py-3 text-xs text-gray-500">
                  <ul className="list-disc space-y-0.5 pl-4">
                    {t.reasons.map((r) => <li key={r}>{r}</li>)}
                  </ul>
                </td>
              </tr>
            ))}
            {!data && !isFetching && (
              <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">No data yet — click Rescan.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      <p className="text-xs text-gray-400">
        Generated by the platform heuristics: same-minute bulk bursts, whole-trail creation inside 48h,
        statutory history compressed into a month, and multi-period trails authored by ≤2 accounts.
        A tenant can always be misclassified — investigate before you act, never before you file.
      </p>
    </div>
  )
}
