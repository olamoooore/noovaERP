import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useToast } from '@/hooks/use-toast'
import { cn } from '@/lib/utils'
import api from '@/lib/api'

export interface TagChip {
  id: number
  name: string
  slug: string
  color: string
}

const COLOR_CLASS: Record<string, string> = {
  slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300',
  blue: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
  emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
  amber: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
  violet: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
  rose: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
  cyan: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-300',
  orange: 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
  teal: 'bg-teal-100 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300',
  pink: 'bg-pink-100 text-pink-700 dark:bg-pink-950/60 dark:text-pink-300',
}

export function tagColorClass(color: string): string {
  return COLOR_CLASS[color] ?? COLOR_CLASS.slate
}

/**
 * Tag chips + picker for a single document. `subjectType` is the API alias
 * (invoice, quotation, sales_order, delivery_order, customer).
 */
export function TagEditor({
  subjectType,
  subjectId,
  readOnly = false,
}: {
  subjectType: string
  subjectId: number | string
  readOnly?: boolean
}) {
  const qc = useQueryClient()
  const { toast } = useToast()
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState('')

  const { data: chips = [] } = useQuery({
    queryKey: ['subject-tags', subjectType, subjectId],
    queryFn: async () => {
      const res = await api.get(`/tags/${subjectType}/${subjectId}`)
      return (res.data?.data ?? []) as TagChip[]
    },
    enabled: Boolean(subjectType && subjectId),
  })

  const { data: allTags = [] } = useQuery({
    queryKey: ['tags'],
    queryFn: async () => {
      const res = await api.get('/tags')
      return (res.data?.data ?? []) as Array<TagChip & { usage_count: number }>
    },
    enabled: open,
    staleTime: 60_000,
  })

  const attachMutation = useMutation({
    mutationFn: async (tags: string[]) => {
      const res = await api.post(`/tags/${subjectType}/${subjectId}`, { tags })
      return res.data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['subject-tags', subjectType, subjectId] })
      qc.invalidateQueries({ queryKey: ['tags'] })
      setDraft('')
    },
    onError: () => toast({ variant: 'destructive', title: 'Could not add tag' }),
  })

  const detachMutation = useMutation({
    mutationFn: async (tagId: number) => {
      const res = await api.delete(`/tags/${subjectType}/${subjectId}/${tagId}`)
      return res.data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['subject-tags', subjectType, subjectId] })
      qc.invalidateQueries({ queryKey: ['tags'] })
    },
    onError: () => toast({ variant: 'destructive', title: 'Could not remove tag' }),
  })

  const addTag = (name: string) => {
    const trimmed = name.trim()
    if (!trimmed) return
    if (chips.some((c) => c.slug === trimmed.toLowerCase().replace(/\s+/g, '-'))) {
      setDraft('')
      return
    }
    attachMutation.mutate([trimmed])
  }

  const suggestions = allTags
    .filter((t) => !chips.some((c) => c.id === t.id))
    .filter((t) => (draft ? t.name.toLowerCase().includes(draft.toLowerCase()) : true))
    .slice(0, 8)

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {chips.map((chip) => (
        <span
          key={chip.id}
          className={cn(
            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
            tagColorClass(chip.color),
          )}
        >
          <Icon icon="solar:tag-bold-duotone" className="h-3 w-3" />
          {chip.name}
          {!readOnly && (
            <button
              type="button"
              onClick={() => detachMutation.mutate(chip.id)}
              className="ml-0.5 opacity-60 transition-opacity hover:opacity-100"
              title="Remove tag"
            >
              <Icon icon="solar:close-circle-bold" className="h-3 w-3" />
            </button>
          )}
        </span>
      ))}

      {!readOnly && (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-6 rounded-full border-dashed px-2 text-[11px]"
            >
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
                placeholder="Type a tag and press Enter"
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
                      <span
                        className={cn('h-2.5 w-2.5 rounded-full', tagColorClass(t.color))}
                      />
                      {t.name}
                      <span className="ml-auto text-[10px] text-muted-foreground">
                        {t.usage_count}
                      </span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </PopoverContent>
        </Popover>
      )}
    </div>
  )
}
