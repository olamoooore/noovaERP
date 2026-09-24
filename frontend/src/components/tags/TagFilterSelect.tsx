import { Icon } from '@iconify/react'

import { useTags } from '@/hooks/use-tags'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

interface TagFilterSelectProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  className?: string
}

/**
 * Reusable "filter by tag" dropdown for document list pages.
 * Fetches the tenant tag list once and reports the selected tag slug
 * (empty string = no tag filter).
 */
export function TagFilterSelect({
  value,
  onChange,
  placeholder = 'All Tags',
  className = 'h-9 w-[140px]',
}: TagFilterSelectProps) {
  const { data: tags = [] } = useTags()

  return (
    <Select value={value || 'all'} onValueChange={(v) => onChange(v === 'all' ? '' : v)}>
      <SelectTrigger className={className}>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">
          <span className="flex items-center gap-2">
            <Icon icon="solar:tag-bold-duotone" className="h-4 w-4 text-muted-foreground" />
            {placeholder}
          </span>
        </SelectItem>
        {tags.map((t) => (
          <SelectItem key={t.id} value={t.slug}>
            {t.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

export default TagFilterSelect
