import { Button } from '@/components/ui/button'

interface BulkSelectionNoticeProps {
  pageSize: number
  allPageSelected: boolean
  selectedCount: number
  allMatchingSelected: boolean
  isSelectingAll: boolean
  onSelectAllMatching: () => void
  noun: string
}

export function BulkSelectionNotice({
  pageSize,
  allPageSelected,
  selectedCount,
  allMatchingSelected,
  isSelectingAll,
  onSelectAllMatching,
  noun,
}: BulkSelectionNoticeProps) {
  if (selectedCount === 0) return null

  return (
    <div className="flex items-center gap-2 text-sm">
      <span>
        {allMatchingSelected
          ? `All ${selectedCount} matching ${noun} selected.`
          : `${selectedCount} ${noun} selected.`}
      </span>
      {!allMatchingSelected && allPageSelected && pageSize > 0 && (
        <Button
          type="button"
          variant="link"
          size="sm"
          className="h-auto p-0"
          disabled={isSelectingAll}
          onClick={onSelectAllMatching}
        >
          {isSelectingAll ? 'Selecting all…' : `Select all matching ${noun}`}
        </Button>
      )}
    </div>
  )
}
