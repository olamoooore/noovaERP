import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import api from '@/lib/api'

export interface SearchHit {
  id: number
  title: string
  subtitle: string
  url: string
  badges: string[]
  tag_slug?: string
}

export interface SearchGroup {
  key: string
  label: string
  icon: string
  items: SearchHit[]
}

export interface SearchResponse {
  query: string
  tag_slugs: string[]
  groups: SearchGroup[]
  total: number
}

export function useGlobalSearch(query: string, tagSlugs: string[], enabled: boolean, limit = 6) {
  return useQuery({
    queryKey: ['global-search', query, tagSlugs, limit],
    queryFn: async () => {
      const res = await api.get('/search', {
        params: {
          q: query || undefined,
          tags: tagSlugs.length ? tagSlugs : undefined,
          limit,
        },
      })
      return res.data as SearchResponse
    },
    enabled,
    staleTime: 15_000,
  })
}

export function useTags(enabled = true) {
  return useQuery({
    queryKey: ['tags'],
    queryFn: async () => {
      const res = await api.get('/tags')
      return (res.data?.data ?? []) as Array<{
        id: number
        name: string
        slug: string
        color: string
        usage_count: number
      }>
    },
    enabled,
    staleTime: 60_000,
  })
}

/**
 * Top-bar omnibox: searches documents, master data and tags, with an
 * optional tag filter chip row.
 */
export function GlobalSearch() {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [tagSlugs, setTagSlugs] = useState<string[]>([])
  const [activeTagPicker, setActiveTagPicker] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const inputRef = useRef<HTMLInputElement | null>(null)

  const { data: tags } = useTags(open)
  const active = open && (query.trim().length > 0 || tagSlugs.length > 0)
  const { data, isFetching } = useGlobalSearch(query, tagSlugs, active, 6)

  // Close on outside click
  useEffect(() => {
    const onDown = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
        setActiveTagPicker(false)
      }
    }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [])

  // ⌘K / Ctrl+K focuses the omnibox
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        inputRef.current?.focus()
        setOpen(true)
      }
      if (e.key === 'Escape') {
        setOpen(false)
        setActiveTagPicker(false)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const selectedTags = useMemo(
    () => (tags ?? []).filter((t) => tagSlugs.includes(t.slug)),
    [tags, tagSlugs],
  )

  const toggleTag = (slug: string) => {
    setTagSlugs((prev) => (prev.includes(slug) ? prev.filter((s) => s !== slug) : [...prev, slug]))
    setOpen(true)
  }

  const go = (url: string) => {
    setOpen(false)
    navigate(url)
  }

  return (
    <div ref={containerRef} className="relative w-full max-w-xl">
      {/* Input */}
      <div className="relative">
        <Icon
          icon="solar:magnifer-bold-duotone"
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        />
        <Input
          ref={inputRef}
          value={query}
          onFocus={() => setOpen(true)}
          onChange={(e) => {
            setQuery(e.target.value)
            setOpen(true)
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && query.trim()) {
              go(`/search?q=${encodeURIComponent(query.trim())}${tagSlugs.length ? `&tag=${tagSlugs.join(',')}` : ''}`)
            }
          }}
          placeholder="Search invoices, quotations, orders, customers, products, tags…"
          className="h-10 pl-9 pr-24"
        />
        <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
          {query && (
            <button
              type="button"
              onClick={() => setQuery('')}
              className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              title="Clear"
            >
              <Icon icon="solar:close-circle-bold-duotone" className="h-4 w-4" />
            </button>
          )}
          <button
            type="button"
            onClick={() => {
              setActiveTagPicker((v) => !v)
              setOpen(true)
            }}
            className={cn(
              'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors',
              tagSlugs.length
                ? 'bg-primary/10 text-primary'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
            title="Filter by tag"
          >
            <Icon icon="solar:tag-bold-duotone" className="h-3.5 w-3.5" />
            {tagSlugs.length || ''}
          </button>
          <kbd className="hidden rounded border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground sm:inline-block">
            ⌘K
          </kbd>
        </div>
      </div>

      {/* Active tag chips */}
      {selectedTags.length > 0 && (
        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
          {selectedTags.map((t) => (
            <button
              key={t.slug}
              type="button"
              onClick={() => toggleTag(t.slug)}
              className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
            >
              <Icon icon="solar:tag-bold-duotone" className="h-3 w-3" />
              {t.name}
              <Icon icon="solar:close-circle-bold" className="h-3 w-3" />
            </button>
          ))}
          <button
            type="button"
            onClick={() => setTagSlugs([])}
            className="text-[11px] text-muted-foreground underline-offset-2 hover:underline"
          >
            clear
          </button>
        </div>
      )}

      {/* Dropdown */}
      {open && (active || activeTagPicker) && (
        <div className="absolute left-0 right-0 top-full z-50 mt-2 max-h-[70vh] overflow-hidden rounded-xl border bg-popover shadow-lg">
          {/* Tag picker */}
          {activeTagPicker && (
            <div className="border-b bg-muted/20 p-3">
              <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Filter by tag
                </span>
                {isFetching && <Icon icon="eos-icons:loading" className="h-3.5 w-3.5" />}
              </div>
              {(tags ?? []).length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  No tags yet — add tags from any invoice, quotation, order or customer.
                </p>
              ) : (
                <div className="flex flex-wrap gap-1.5">
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
            </div>
          )}

          {/* Results */}
          <div className="max-h-[55vh] overflow-y-auto">
            {!active ? null : isFetching && !data ? (
              <div className="flex items-center justify-center py-10">
                <Icon icon="eos-icons:loading" className="h-6 w-6 animate-spin text-primary" />
              </div>
            ) : (data?.groups ?? []).length === 0 ? (
              <div className="flex flex-col items-center gap-1 py-10 text-muted-foreground">
                <Icon icon="solar:magnifer-bold-duotone" className="h-8 w-8 opacity-40" />
                <p className="text-sm font-medium">No matches</p>
                <p className="text-xs">Try another term or remove the tag filter.</p>
              </div>
            ) : (
              <div className="divide-y">
                {(data?.groups ?? []).map((group) => (
                  <div key={group.key} className="p-2">
                    <div className="flex items-center gap-1.5 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                      <Icon icon={group.icon} className="h-3.5 w-3.5" />
                      {group.label}
                    </div>
                    {group.items.map((item) => (
                      <button
                        key={`${group.key}-${item.id}`}
                        type="button"
                        onClick={() =>
                          item.tag_slug
                            ? toggleTag(item.tag_slug)
                            : go(item.url)
                        }
                        className="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-muted/60"
                      >
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-medium">{item.title}</span>
                          <span className="block truncate text-xs text-muted-foreground">
                            {item.subtitle}
                          </span>
                        </span>
                        {item.badges.length > 0 && (
                          <span className="flex shrink-0 gap-1">
                            {item.badges.slice(0, 3).map((b) => (
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
                          className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                        />
                      </button>
                    ))}
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Footer */}
          {active && (data?.total ?? 0) > 0 && (
            <button
              type="button"
              onClick={() =>
                go(`/search?q=${encodeURIComponent(query)}${tagSlugs.length ? `&tag=${tagSlugs.join(',')}` : ''}`)
              }
              className="flex w-full items-center justify-center gap-2 border-t bg-muted/20 py-2.5 text-xs font-medium text-primary transition-colors hover:bg-muted/40"
            >
              View all results ({data?.total})
              <Icon icon="solar:alt-arrow-right-bold-duotone" className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
      )}
    </div>
  )
}
