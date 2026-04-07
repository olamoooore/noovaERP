// ─── Pure diff computation ────────────────────────────────────────────────────

const SKIP_FIELDS = new Set([
  'updated_at', 'created_at', 'remember_token', 'password',
  'two_factor_secret', 'two_factor_recovery_codes',
])

export interface DiffField {
  field: string
  before: unknown
  after: unknown
}

export function computeDiff(
  before: Record<string, unknown> | null,
  after: Record<string, unknown> | null,
): DiffField[] {
  const b = before ?? {}
  const a = after  ?? {}
  const allKeys = [...new Set([...Object.keys(b), ...Object.keys(a)])]

  return allKeys
    .filter(k => !SKIP_FIELDS.has(k))
    .filter(k => JSON.stringify(b[k] ?? null) !== JSON.stringify(a[k] ?? null))
    .map(k => ({ field: k, before: b[k] ?? null, after: a[k] ?? null }))
}

// ─── Value renderer ───────────────────────────────────────────────────────────

function renderVal(v: unknown): string {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'boolean') return v ? 'true' : 'false'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

// ─── Component ────────────────────────────────────────────────────────────────

interface FieldDiffProps {
  before: Record<string, unknown> | null
  after:  Record<string, unknown> | null
  /** Pre-computed changes from API /show response — falls back to client-side diff */
  changes?: DiffField[]
}

export function FieldDiff({ before, after, changes }: FieldDiffProps) {
  const diffs = changes ?? computeDiff(before, after)

  if (diffs.length === 0) {
    const hasData = (before && Object.keys(before).length > 0) || (after && Object.keys(after).length > 0)
    return (
      <p className="text-xs text-gray-400 italic">
        {hasData ? 'No field changes detected.' : 'No snapshot data recorded.'}
      </p>
    )
  }

  return (
    <div className="overflow-hidden rounded-lg border border-gray-200 text-xs">
      <div className="grid grid-cols-3 bg-gray-50 border-b border-gray-200 font-semibold text-gray-500 uppercase tracking-wide">
        <div className="px-3 py-2">Field</div>
        <div className="px-3 py-2 text-red-500">Before</div>
        <div className="px-3 py-2 text-green-600">After</div>
      </div>
      {diffs.map(({ field, before: bv, after: av }) => (
        <div
          key={field}
          className="grid grid-cols-3 border-b border-gray-100 last:border-0 hover:bg-gray-50"
        >
          <div className="px-3 py-2 font-mono text-gray-700 font-medium">{field}</div>
          <div className="px-3 py-2 font-mono text-red-600 break-all">{renderVal(bv)}</div>
          <div className="px-3 py-2 font-mono text-green-700 break-all">{renderVal(av)}</div>
        </div>
      ))}
    </div>
  )
}
