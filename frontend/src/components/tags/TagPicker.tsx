import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import api from '@/lib/api'
import { tagColorClass } from '@/components/tags/TagEditor'

export interface TagOption {
  id: number
  name: string
  slug: string
  color: string
  usage_count?: number
}

const slugify = (s: string) => s.trim().toLowerCase().replace(/\s+/g, '-')

/**
 * Controlled tag selector for CREATE forms — collects tag names locally so the
 * document can be tagged in the same request that creates it. The subject-based
 * TagEditor (which persists immediately) is used once the record exists.
 */
export function TagPicker({
  value = [],
  onChange,
  label = 'Tags',
  placeholder = 'Type a tag and press Enter',
}: {
  value?: string[]
  onChange: (tags: string[]) => void
  label?: string
  placeholder?: string
}) {
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState('')

  const { data: allTags = [] } = useQuery({
    queryKey: ['tags'],
    queryFn: async () => {
      const res = await api.get('/tags')
      return (res.data?.data ?? []) as TagOption[]
    },
    staleTime: 60_000,
  })

  const addTag = (name: string) => {
    const trimmed = name.trim()
    if (!trimmed) return
    if (!value.some((v) => slugify(v) === slugify(trimmed))) {
      onChange([...value, trimmed])
    }
    setDraft('')
  }

  const removeTag = (name: string) => onChange(value.filter((v) => v !== name))

  const suggestions = allTags
    .filter((t) => !value.some((v) => slugify(v) === t.slug))
    .filter((t) => (draft ? t.name.toLowerCase().includes(draft.toLowerCase()) : true))
    .slice(0, 8)

  const colorFor = (name: string) => allTags.find((t) => t.slug === slugify(name))?.color ?? 'slate'

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <span className="mr-0.5 flex items-center gap-1 text-xs font-medium text-muted-foreground">
        <Icon icon="solar:tag-bold-duotone" className="h-3.5 w-3.5" />
        {label}
      </span>

      {value.map((name) => (
        <span
          key={name}
          className={cn(
            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
            tagColorClass(colorFor(name)),
          )}
        >
          <Icon icon="solar:tag-bold-duotone" className="h-3 w-3" />
          {name}
          <button
            type="button"
            onClick={() => removeTag(name)}
            className="ml-0.5 opacity-60 transition-opacity hover:opacity-100"
            title="Remove tag"
          >
            <Icon icon="solar:close-circle-bold" className="h-3 w-3" />
          </button>
        </span>
      ))}

      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button variant="outline" size="sm" className="h-6 rounded-full border-dashed px-2 text-[11px]">
            <Icon icon="solar:add-circle-bold-duotone" className="mr-1 h-3 w-3" />
            Tag
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-72 p-3">
          <div className="space-y-2">
            <Input
              autoFocus
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  addTag(draft)
                }
              }}
              placeholder={placeholder}
              className="h-8 text-sm"
            />
            {draft.trim() && !suggestions.some((s) => s.name.toLowerCase() === draft.trim().toLowerCase()) && (
              <button
                type="button"
                onClick={() => addTag(draft)}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-muted"
              >
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 text-primary" />
                Create “{draft.trim()}”
              </button>
            )}
            {suggestions.length > 0 && (
              <div className="max-h-52 space-y-0.5 overflow-y-auto">
                {suggestions.map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    onClick={() => addTag(t.name)}
                    className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-muted"
                  >
                    <span className={cn('h-2.5 w-2.5 rounded-full', tagColorClass(t.color))} />
                    {t.name}
                    <span className="ml-auto text-[10px] text-muted-foreground">{t.usage_count}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        </PopoverContent>
      </Popover>
    </div>
  )
}
