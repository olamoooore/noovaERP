import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

const PAGE_SIZE_OPTIONS = [20, 40, 60, 80, 100]

interface PaginationProps {
  page: number
  totalPages: number
  onPageChange: (page: number) => void
  from?: number | null
  to?: number | null
  total?: number | null
  /** Plural noun shown in "Showing x–y of z <label>". */
  label?: string
  className?: string
  /** Current rows-per-page. Supplying both size props renders the "Show N" selector. */
  pageSize?: number
  onPageSizeChange?: (size: number) => void
}

/**
 * Compact list pagination: range summary on the left, then round arrow buttons
 * with an editable page-number field between them — type a page and press
 * Enter (or click away) to jump.
 */
export function Pagination({
  page,
  totalPages,
  onPageChange,
  from,
  to,
  total,
  label = 'items',
  className = '',
  pageSize,
  onPageSizeChange,
}: PaginationProps) {
  const [jumpTo, setJumpTo] = useState(String(page))

  // Keep the field in step when paging via the arrows.
  useEffect(() => {
    setJumpTo(String(page))
  }, [page])

  const goToPage = () => {
    const parsed = Number.parseInt(jumpTo, 10)
    if (!Number.isFinite(parsed)) {
      setJumpTo(String(page))
      return
    }
    const clamped = Math.min(totalPages, Math.max(1, parsed))
    setJumpTo(String(clamped))
    if (clamped !== page) {
      onPageChange(clamped)
    }
  }

  const showRange = from !== undefined || to !== undefined || total !== undefined
  const hasPages = totalPages > 1
  const showSizeSelector = typeof pageSize === 'number' && typeof onPageSizeChange === 'function'
  const sizeOptions = showSizeSelector && !PAGE_SIZE_OPTIONS.includes(pageSize as number)
    ? [...PAGE_SIZE_OPTIONS, pageSize as number].sort((a, b) => a - b)
    : PAGE_SIZE_OPTIONS

  // Always render the range summary — on a single-page list it still tells the
  // user the total ("Showing 1–13 of 13 customers") instead of hiding it.
  if (!showRange && !hasPages) {
    return null
  }

  return (
    <div className={`flex flex-wrap items-center justify-between gap-3 pt-4 border-t mt-4 ${className}`}>
      {showRange ? (
        <div className="text-sm text-muted-foreground">
          Showing {from ?? 0}–{to ?? 0} of {total ?? 0} {label}
        </div>
      ) : (
        <div />
      )}

      <div className="flex flex-wrap items-center gap-2">
        {showSizeSelector && (
          <div className="flex items-center gap-1.5">
            <span className="text-sm text-muted-foreground">Show</span>
            <Select value={String(pageSize)} onValueChange={(value) => onPageSizeChange?.(Number(value))}>
              <SelectTrigger className="h-8 w-[76px] rounded-full text-xs" aria-label="Rows per page">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {sizeOptions.map((size) => (
                  <SelectItem key={size} value={String(size)}>
                    {size}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {hasPages && (
          <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            className="h-8 rounded-full px-3 text-xs"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
            aria-label="Previous page"
          >
            <Icon icon="solar:arrow-left-bold" className="h-3.5 w-3.5" />
          </Button>

          <Input
            type="number"
            min={1}
            max={totalPages}
            value={jumpTo}
            onChange={(event) => setJumpTo(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                goToPage()
              }
            }}
            onBlur={goToPage}
            className="h-8 w-14 rounded-full px-2 text-center text-xs"
            aria-label={`Page number (1–${totalPages})`}
          />

          <Button
            type="button"
            variant="outline"
            className="h-8 rounded-full px-3 text-xs"
            disabled={page >= totalPages}
            onClick={() => onPageChange(page + 1)}
            aria-label="Next page"
          >
            <Icon icon="solar:arrow-right-bold" className="h-3.5 w-3.5" />
          </Button>
          </div>
        )}
      </div>
    </div>
  )
}

export default Pagination
