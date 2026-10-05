import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { ReportScope } from '@/hooks/useReportScope'
import { ALL_ENTITIES } from '@/hooks/useReportScope'

interface ReportScopeHeaderProps {
  scope: ReportScope
}

/**
 * #866 (RPT-L10N UX4): sticky header every financial report shares — legal
 * entity selector, reporting-currency badge, and (for mixed-currency tenants)
 * the guard that amounts are never summed across currencies. The toolbar it
 * feeds into exports through the page's export subtitle.
 */
export function ReportScopeHeader({ scope }: ReportScopeHeaderProps) {
  const {
    entities,
    entityId,
    setEntityId,
    currencyCode,
    currencies,
    multiCurrency,
    missingScope,
  } = scope

  return (
    <div
      data-testid="report-scope-header"
      className="sticky top-0 z-30 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80"
    >
      <div className="flex items-center gap-2">
        <Icon icon="solar:buildings-2-bold-duotone" className="h-4 w-4 text-muted-foreground" />
        <span className="text-xs font-medium text-muted-foreground">Entity</span>
        <Select value={entityId} onValueChange={setEntityId} disabled={entities.length === 0}>
          <SelectTrigger
            id="report-scope-entity"
            aria-label="Legal entity"
            data-testid="report-scope-entity"
            className="h-8 w-64 rounded-full px-3 text-xs"
          >
            <SelectValue placeholder="Select legal entity" />
          </SelectTrigger>
          <SelectContent>
            {!multiCurrency && <SelectItem value={ALL_ENTITIES}>All entities</SelectItem>}
            {entities.map((entity) => (
              <SelectItem key={entity.id} value={String(entity.id)}>
                {entity.name}
                {entity.base_currency ? ` · ${entity.base_currency.toUpperCase()}` : ''}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex items-center gap-2">
        <span className="text-xs font-medium text-muted-foreground">Reporting currency</span>
        {currencyCode ? (
          <Badge
            variant="outline"
            data-testid="report-scope-currency"
            className="bg-emerald-50 font-mono text-xs text-emerald-700"
          >
            {currencyCode}
          </Badge>
        ) : (
          <Badge
            variant="outline"
            data-testid="report-scope-currency"
            className="border-amber-200 bg-amber-50 text-xs text-amber-700"
          >
            Not set
          </Badge>
        )}
      </div>

      {multiCurrency && (
        <p className="text-xs text-amber-700" data-testid="report-scope-hint">
          {currencies.join(', ')} in scope — amounts are never summed across currencies
          {missingScope ? '; pick one entity above to run this report.' : '.'}
        </p>
      )}
    </div>
  )
}
