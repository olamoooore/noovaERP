/**
 * D7 / issue #344: the invoice pre-flight checklist (InvoicePreflightService).
 * A `fail` is a hard gate — the server refuses Post with the same reasons —
 * so the UI disables Post (and Save & Send, which posts) and says why.
 */
export type PreflightStatus = 'pass' | 'warn' | 'fail'

export interface PreflightCheck {
  key: string
  label: string
  status: PreflightStatus
  detail: string
}

export interface PreflightReport {
  ready: boolean
  passed: number
  total: number
  checks: PreflightCheck[]
}

export function failingChecks(report: PreflightReport | null | undefined): PreflightCheck[] {
  return (report?.checks ?? []).filter((check) => check.status === 'fail')
}
