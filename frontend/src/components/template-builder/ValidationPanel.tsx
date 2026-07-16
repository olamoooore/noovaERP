import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import { clsx } from 'clsx'
import { templateBuilderApi } from '@/lib/templateBuilderApi'
import { useTemplateEditorStore } from '@/store/templateEditorStore'

interface ValidationIssue {
  severity: 'error' | 'warning'
  message: string
}

/**
 * ADR-004 §12 — validation panel.
 * Surfaces backend {@see validateDefinition} errors plus merge-field warnings
 * from the last preview.
 */
export function ValidationPanel() {
  const template = useTemplateEditorStore((s) => s.template)
  const previewWarnings = useTemplateEditorStore((s) => s.previewWarnings)
  const [issues, setIssues] = useState<ValidationIssue[]>([])
  const [checking, setChecking] = useState(false)

  const runCheck = async () => {
    if (!template) return
    setChecking(true)
    try {
      const res = await templateBuilderApi.validate({
        mode: template.mode ?? 'block',
        layout_config: template.layout_config,
        html: template.html ?? undefined,
      })
      const errs: ValidationIssue[] = res.errors.map((e) => ({ severity: 'error', message: e }))
      const warns: ValidationIssue[] = previewWarnings.map((w) => ({ severity: 'warning', message: w }))
      setIssues([...errs, ...warns])
    } catch {
      setIssues(previewWarnings.map((w) => ({ severity: 'warning', message: w })))
    } finally {
      setChecking(false)
    }
  }

  useEffect(() => {
    if (!template) {
      setIssues([])
      return
    }
    void runCheck()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [template?.layout_config, template?.html, previewWarnings])

  const errorCount = issues.filter((i) => i.severity === 'error').length
  const warnCount = issues.filter((i) => i.severity === 'warning').length

  return (
    <div className="flex flex-col h-full">
      <div className="flex items-center justify-between px-2 py-1.5 border-b">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Validation</span>
        <div className="flex items-center gap-2">
          {errorCount > 0 && (
            <span className="text-[10px] font-bold text-red-600">{errorCount} err</span>
          )}
          {warnCount > 0 && (
            <span className="text-[10px] font-bold text-amber-600">{warnCount} warn</span>
          )}
          {errorCount === 0 && warnCount === 0 && !checking && (
            <Icon icon="mdi:check-circle" className="h-4 w-4 text-green-600" />
          )}
          <button onClick={runCheck} title="Re-check" className="p-1 rounded hover:bg-gray-100">
            <Icon icon={checking ? 'mdi:loading spin' : 'mdi:refresh'} className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
      <div className="flex-1 overflow-auto p-2 space-y-1">
        {issues.length === 0 && !checking && (
          <div className="text-xs text-gray-400 italic">No issues — definition is valid.</div>
        )}
        {issues.map((issue, i) => (
          <div
            key={i}
            className={clsx(
              'flex items-start gap-1.5 rounded px-1.5 py-1 text-xs',
              issue.severity === 'error' ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-800',
            )}
          >
            <Icon
              icon={issue.severity === 'error' ? 'mdi:alert-circle' : 'mdi:alert'}
              className="h-3.5 w-3.5 mt-0.5 shrink-0"
            />
            <span>{issue.message}</span>
          </div>
        ))}
      </div>
    </div>
  )
}
