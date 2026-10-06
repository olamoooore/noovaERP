import { AlertTriangle, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import { cn } from '@/lib/utils'
import type { PreviewDocumentOption } from '@/lib/templateBuilderApi'

interface Props {
  html: string | null
  warnings: string[]
  loading: boolean
  onRefresh: () => void
  /** Issue #620: recent real documents the user may preview the draft against. */
  documents?: PreviewDocumentOption[]
  /** Selected real document id; '' (or undefined) means sample data. */
  documentId?: string
  onDocumentChange?: (documentId: string) => void
}

export function PreviewPane({ html, warnings, loading, onRefresh, documents = [], documentId = '', onDocumentChange }: Props) {
  const selected = documents.find((d) => d.id === documentId)
  return (
    <div className="flex h-full flex-col bg-muted/30">
      <div className="flex items-center justify-between gap-2 border-b bg-background px-3 py-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {selected ? `Preview with ${selected.number}` : 'Live Preview'}
        </h3>
        <div className="flex items-center gap-2">
          {onDocumentChange && (
            <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
              Preview with
              <select
                aria-label="Preview document"
                className="h-8 max-w-[220px] rounded border bg-background px-2 text-xs text-foreground"
                value={documentId}
                onChange={(e) => onDocumentChange(e.target.value)}
                disabled={loading}
              >
                <option value="">Sample data</option>
                {documents.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.label}
                  </option>
                ))}
              </select>
            </label>
          )}
          <Button variant="ghost" size="sm" onClick={onRefresh} disabled={loading}>
            <RefreshCw className={cn('mr-1.5 h-3.5 w-3.5', loading && 'animate-spin')} />
            Refresh
          </Button>
        </div>
      </div>

      {warnings.length > 0 && (
        <div className="border-b bg-amber-50 px-3 py-2">
          <div className="flex items-center gap-1.5 text-xs font-medium text-amber-700">
            <AlertTriangle className="h-3.5 w-3.5" />
            {warnings.length} warning{warnings.length === 1 ? '' : 's'}
          </div>
          <ul className="mt-1 max-h-20 list-disc space-y-0.5 overflow-auto pl-5 text-[11px] text-amber-700">
            {warnings.slice(0, 10).map((w, i) => (
              <li key={i}>{w}</li>
            ))}
          </ul>
        </div>
      )}

      <ScrollArea className="flex-1">
        <div className="flex justify-center p-4">
          {html ? (
            <iframe
              title="Template preview"
              srcDoc={html}
              sandbox=""
              className="h-[80vh] w-full max-w-[620px] rounded border bg-white shadow-sm"
            />
          ) : (
            <p className="py-20 text-center text-sm text-muted-foreground">
              Click <strong>Refresh</strong> to render the template against {selected ? selected.number : 'sample data'}.
            </p>
          )}
        </div>
      </ScrollArea>
    </div>
  )
}
