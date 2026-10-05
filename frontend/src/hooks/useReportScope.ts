import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { entityManagementApi, type LegalEntity } from '@/services/closeApi'
import { useCurrencyCode } from '@/lib/utils'

/**
 * #866 (RPT-L10N UX4): the entity + currency scope shared by every financial
 * report header.
 *
 * #855 made the reports API refuse to sum legal entities with different base
 * currencies unless one entity is selected. This hook owns that selection:
 * `legalEntityId` scopes the query, `currencyCode` is the only truthful
 * reporting currency (the selected entity's base currency, or the tenant
 * currency when every entity shares it), and `missingScope` blocks the query
 * until a mixed-currency tenant picks an entity. Presentation-currency
 * translation is #855's remaining work; until it lands, amounts are never
 * relabelled to a currency the backend did not produce.
 */
export interface ReportScope {
  entities: LegalEntity[]
  entityId: string
  setEntityId: (value: string) => void
  selectedEntity: LegalEntity | undefined
  legalEntityId: number | undefined
  currencyCode: string | undefined
  currencies: string[]
  multiCurrency: boolean
  missingScope: boolean
  scopeLabel: string
}

export const ALL_ENTITIES = 'all'

export function useReportScope(): ReportScope {
  const tenantCurrency = useCurrencyCode()
  const [entityId, setEntityId] = useState<string>(ALL_ENTITIES)

  const { data } = useQuery({
    queryKey: ['legal-entities'],
    queryFn: () => entityManagementApi.listEntities().then((response) => response.data),
    staleTime: 5 * 60 * 1000,
    retry: false,
  })

  const entities = useMemo<LegalEntity[]>(() => {
    const raw = data as unknown
    if (Array.isArray(raw)) return raw as LegalEntity[]
    return ((raw as { data?: LegalEntity[] } | undefined)?.data ?? []) as LegalEntity[]
  }, [data])

  const currencies = useMemo(
    () =>
      Array.from(
        new Set(
          entities
            .map((entity) => entity.base_currency?.toUpperCase())
            .filter((code): code is string => Boolean(code)),
        ),
      ),
    [entities],
  )

  const multiCurrency = currencies.length > 1

  const selectedEntity = useMemo(
    () => entities.find((entity) => String(entity.id) === entityId),
    [entities, entityId],
  )

  const currencyCode =
    selectedEntity?.base_currency?.toUpperCase() ??
    (multiCurrency ? undefined : currencies[0] ?? tenantCurrency)

  const missingScope = multiCurrency && !selectedEntity

  const scopeLabel = selectedEntity
    ? `${selectedEntity.name}${currencyCode ? ` · ${currencyCode}` : ''}`
    : `${currencyCode ?? tenantCurrency} · all entities`

  return {
    entities,
    entityId,
    setEntityId,
    selectedEntity,
    legalEntityId: selectedEntity?.id,
    currencyCode,
    currencies,
    multiCurrency,
    missingScope,
    scopeLabel,
  }
}
