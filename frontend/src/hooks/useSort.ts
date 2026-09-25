import { useMemo, useState } from 'react'

export type SortDirection = 'asc' | 'desc'

/**
 * Click-to-sort for a loaded row array.
 *
 * Numeric values compare numerically, everything else uses a locale compare
 * with `numeric: true` (so "10" sorts after "9"), blanks sort last, and
 * toggling the same column flips the direction.
 */
export function useSort<T>(rows: T[], defaultKey: string | null = null, defaultDirection: SortDirection = 'asc') {
  const [sortKey, setSortKey] = useState<string | null>(defaultKey)
  const [sortDir, setSortDir] = useState<SortDirection>(defaultDirection)

  const toggle = (key: string) => {
    if (sortKey === key) {
      setSortDir((current) => (current === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortKey(key)
      setSortDir('asc')
    }
  }

  const sorted = useMemo(() => {
    if (!sortKey) return rows

    const read = (row: unknown, key: string): unknown => {
      if (row == null || typeof row !== 'object') return undefined
      return key.split('.').reduce<unknown>((value, part) => {
        if (value == null || typeof value !== 'object') return undefined
        return (value as Record<string, unknown>)[part]
      }, row)
    }

    const compare = (a: unknown, b: unknown): number => {
      const aBlank = a == null || a === ''
      const bBlank = b == null || b === ''
      if (aBlank && bBlank) return 0
      if (aBlank) return 1
      if (bBlank) return -1
      if (typeof a === 'number' && typeof b === 'number') return a - b
      return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' })
    }

    const copy = [...rows]
    copy.sort((a, b) => {
      const result = compare(read(a, sortKey), read(b, sortKey))
      return sortDir === 'asc' ? result : -result
    })

    return copy
  }, [rows, sortKey, sortDir])

  return { sorted, sortKey, sortDir, toggle }
}

export default useSort
