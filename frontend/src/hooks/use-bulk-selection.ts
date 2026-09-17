import { useCallback, useEffect, useState } from 'react'

interface UseBulkSelectionOptions {
  pageIds: number[]
  scopeKey: string
  fetchAllIds: () => Promise<number[]>
  onSelectAllError?: (error: unknown) => void
}

/**
 * Shared selection state for paginated listings.
 *
 * The header toggle selects the current page. A separate action can promote the
 * selection to every matching record. Changing filters clears stale selection.
 */
export function useBulkSelection({
  pageIds,
  scopeKey,
  fetchAllIds,
  onSelectAllError,
}: UseBulkSelectionOptions) {
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [isSelectingAll, setIsSelectingAll] = useState(false)
  const [allMatchingSelected, setAllMatchingSelected] = useState(false)

  useEffect(() => {
    setSelectedIds([])
    setAllMatchingSelected(false)
  }, [scopeKey])

  const toggleSelect = useCallback((id: number) => {
    setAllMatchingSelected(false)
    setSelectedIds((previous) => previous.includes(id)
      ? previous.filter((selectedId) => selectedId !== id)
      : [...previous, id])
  }, [])

  const clearSelection = useCallback(() => {
    setSelectedIds([])
    setAllMatchingSelected(false)
  }, [])

  const allPageSelected = pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id))

  const togglePageSelection = useCallback(() => {
    setAllMatchingSelected(false)
    setSelectedIds((previous) => {
      const pageIsSelected = pageIds.every((id) => previous.includes(id))
      return pageIsSelected
        ? previous.filter((id) => !pageIds.includes(id))
        : pageIds
    })
  }, [pageIds])

  const selectAllMatching = useCallback(async () => {
    setIsSelectingAll(true)
    try {
      setSelectedIds(await fetchAllIds())
      setAllMatchingSelected(true)
    } catch (error) {
      onSelectAllError?.(error)
    } finally {
      setIsSelectingAll(false)
    }
  }, [fetchAllIds, onSelectAllError])

  return {
    selectedIds,
    clearSelection,
    toggleSelect,
    togglePageSelection,
    selectAllMatching,
    allPageSelected,
    allMatchingSelected,
    isSelectingAll,
  }
}
