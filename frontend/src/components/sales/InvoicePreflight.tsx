import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { failingChecks, type PreflightReport, type PreflightStatus } from '@/lib/invoicePreflight'

/** D7 / issue #344: pre-flight checklist UI (see @/lib/invoicePreflight). */
export function PreflightCheckIcon({ status }: { status: PreflightStatus }) {
  return (
    <Icon
      icon={
        status === 'pass'
          ? 'solar:check-circle-bold'
          : status === 'warn'
            ? 'solar:danger-triangle-bold'
            : 'solar:close-circle-bold'
      }
      className={cn(
        'mt-0.5 h-4 w-4 shrink-0',
        status === 'pass' ? 'text-emerald-600' : status === 'warn' ? 'text-amber-600' : 'text-red-600',
      )}
    />
  )
}

/** The failing checks that block posting, with their reasons. Renders nothing when none fail. */
export function PreflightBlockedNotice({
  report,
  action = 'Post',
  className,
}: {
  report: PreflightReport | null | undefined
  action?: string
  className?: string
}) {
  const failed = failingChecks(report)

  if (failed.length === 0) return null

  return (
    <div
      role="alert"
      data-testid="preflight-blocked"
      className={cn(
        'rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300',
        className,
      )}
    >
      <div className="font-semibold">{action} is blocked until these checks pass:</div>
      <ul className="mt-1 space-y-0.5">
        {failed.map((check) => (
          <li key={check.key}>
            <span className="font-medium">{check.label}:</span> {check.detail}
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Full checklist (pass / warn / fail rows), as shown on the create/edit form. */
export function PreflightChecklist({ report, className }: { report: PreflightReport; className?: string }) {
  return (
    <div data-testid="preflight-checklist" className={cn('rounded-md border text-xs', className)}>
      <div className="border-b px-3 py-2 font-semibold">
        Pre-flight checklist · {report.passed}/{report.total} ready
      </div>
      <ul className="p-1.5">
        {report.checks.map((check) => (
          <li key={check.key} className="flex gap-2 rounded-md p-2" data-status={check.status}>
            <PreflightCheckIcon status={check.status} />
            <div className="min-w-0">
              <div className="font-medium">{check.label}</div>
              <div className="text-muted-foreground">{check.detail}</div>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
