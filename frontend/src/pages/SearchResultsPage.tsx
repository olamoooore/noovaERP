import { useMemo } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useGlobalSearch, useTags } from '@/components/search/GlobalSearch'

/**
 * Full-page search results — the "view all" target of the top-bar omnibox.
 * Supports ?q=<term> and ?tag=<slug,slug>.
 */
export default function SearchResultsPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()

  const query = params.get('q') ?? ''
  const tagSlugs = useMemo(
    () => (params.get('tag') ?? '').split(',').map((s) => s.trim()).filter(Boolean),
    [params],
  )

  const { data: tags } = useTags()
  const { data, isLoading } = useGlobalSearch(query, tagSlugs, query.trim().length > 0 || tagSlugs.length > 0, 25)

  const setQuery = (value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set('q', value)
    else next.delete('q')
    setParams(next, { replace: true })
  }

  const toggleTag = (slug: string) => {
    const next = new URLSearchParams(params)
    const set = new Set(tagSlugs)
    if (set.has(slug)) set.delete(slug)
    else set.add(slug)
    if (set.size) next.set('tag', Array.from(set).join(','))
    else next.delete('tag')
    setParams(next, { replace: true })
  }

  const total = data?.total ?? 0

  return (
    <div className="container mx-auto space-y-6 py-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Search</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Find invoices, quotations, sales orders, delivery orders, customers, products and tags.
        </p>
      </div>

      {/* Query bar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <Icon
            icon="solar:magnifer-bold-duotone"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search everything…"
            className="h-10 pl-9"
            autoFocus
          />
        </div>
        {(query || tagSlugs.length > 0) && (
          <Button variant="outline" onClick={() => setParams(new URLSearchParams(), { replace: true })}>
            <Icon icon="solar:refresh-circle-bold-duotone" className="mr-2 h-4 w-4" />
            Reset
          </Button>
        )}
      </div>

      {/* Tag filter */}
      {(tags ?? []).length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Tags
          </span>
          {(tags ?? []).map((t) => {
            const on = tagSlugs.includes(t.slug)
            return (
              <button
                key={t.slug}
                type="button"
                onClick={() => toggleTag(t.slug)}
                className={cn(
                  'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                  on
                    ? 'border-primary/40 bg-primary/10 text-primary'
                    : 'border-border text-muted-foreground hover:border-primary/30 hover:bg-muted/60 hover:text-foreground',
                )}
              >
                <Icon icon="solar:tag-bold-duotone" className="h-3.5 w-3.5" />
                {t.name}
                <span className="text-[10px] opacity-70">{t.usage_count}</span>
              </button>
            )
          })}
        </div>
      )}

      {/* Results */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
        </div>
      ) : total === 0 ? (
        <Card className="overflow-hidden">
          <CardContent className="flex flex-col items-center justify-center py-16 text-muted-foreground">
            <Icon icon="solar:magnifer-bold-duotone" className="mb-3 h-12 w-12 opacity-40" />
            <p className="text-sm font-medium">
              {query || tagSlugs.length ? 'No matches found' : 'Start typing to search'}
            </p>
            <p className="mt-1 text-xs">
              {query || tagSlugs.length
                ? 'Try a different term, or remove a tag filter.'
                : 'Search by document number, customer, product or tag.'}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">
            {total} result{total === 1 ? '' : 's'}
            {query && <> for “{query}”</>}
            {tagSlugs.length > 0 && <> tagged {tagSlugs.join(', ')}</>}
          </p>

          {(data?.groups ?? []).map((group) => (
            <Card key={group.key} className="overflow-hidden">
              <div className="flex items-center gap-2 border-b bg-muted/20 px-6 py-3">
                <Icon icon={group.icon} className="h-4 w-4 text-primary" />
                <span className="text-sm font-semibold">{group.label}</span>
                <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                  {group.items.length}
                </span>
              </div>
              <CardContent className="p-0">
                <div className="divide-y">
                  {group.items.map((item) => (
                    <button
                      key={`${group.key}-${item.id}`}
                      type="button"
                      onClick={() => item.tag_slug ? toggleTag(item.tag_slug) : navigate(item.url)}
                      className="flex w-full items-center justify-between gap-3 px-6 py-3 text-left transition-colors hover:bg-muted/40"
                    >
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium">{item.title}</span>
                        <span className="block truncate text-xs text-muted-foreground">
                          {item.subtitle}
                        </span>
                      </span>
                      {item.badges.length > 0 && (
                        <span className="flex shrink-0 flex-wrap gap-1">
                          {item.badges.slice(0, 4).map((b) => (
                            <span
                              key={b}
                              className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                            >
                              {b}
                            </span>
                          ))}
                        </span>
                      )}
                      <Icon
                        icon="solar:alt-arrow-right-bold-duotone"
                        className="h-4 w-4 shrink-0 text-muted-foreground"
                      />
                    </button>
                  ))}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
