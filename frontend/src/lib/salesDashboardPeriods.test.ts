import { describe, it, expect } from 'vitest'
import { percentChange, resolveSalesDashboardPeriod, type PeriodSelection } from './salesDashboardPeriods'

const base: PeriodSelection = { period: 'month', month: 0, year: 2026, customStart: '', customEnd: '' }
const resolve = (sel: Partial<PeriodSelection>, now: Date) => {
  const r = resolveSalesDashboardPeriod({ ...base, ...sel }, now)
  if (!r.ok) throw new Error(r.error)
  return r.value
}

describe('resolveSalesDashboardPeriod (#358)', () => {
  it('compares a closed January with the previous December', () => {
    const v = resolve({ period: 'month', month: 0, year: 2025 }, new Date(2026, 9, 14))
    expect(v.current).toEqual({ start_date: '2025-01-01', end_date: '2025-01-31' })
    expect(v.previous).toEqual({ start_date: '2024-12-01', end_date: '2024-12-31' })
    expect(v.label).toBe('January 2025')
  })

  it('caps the comparison day at the end of a shorter previous month', () => {
    const v = resolve({ period: 'month', month: 2, year: 2026 }, new Date(2026, 2, 31))
    expect(v.current).toEqual({ start_date: '2026-03-01', end_date: '2026-03-31' })
    expect(v.previous).toEqual({ start_date: '2026-02-01', end_date: '2026-02-28' })
  })

  it('compares a leap-day year to date with the same span of the previous year', () => {
    const v = resolve({ period: 'year', year: 2028 }, new Date(2028, 1, 29))
    expect(v.current).toEqual({ start_date: '2028-01-01', end_date: '2028-02-29' })
    expect(v.previous).toEqual({ start_date: '2027-01-01', end_date: '2027-02-28' })
  })

  it('compares a closed year with the full previous year', () => {
    const v = resolve({ period: 'year', year: 2024 }, new Date(2026, 9, 14))
    expect(v.current).toEqual({ start_date: '2024-01-01', end_date: '2024-12-31' })
    expect(v.previous).toEqual({ start_date: '2023-01-01', end_date: '2023-12-31' })
  })

  it('compares the week to date with the same days of the previous week', () => {
    const v = resolve({ period: 'week' }, new Date(2026, 9, 14))
    expect(v.current).toEqual({ start_date: '2026-10-11', end_date: '2026-10-14' })
    expect(v.previous).toEqual({ start_date: '2026-10-04', end_date: '2026-10-07' })
  })

  it('compares a one-day custom range with the day before', () => {
    const v = resolve({ period: 'custom', customStart: '2026-03-01', customEnd: '2026-03-01' }, new Date(2026, 9, 14))
    expect(v.previous).toEqual({ start_date: '2026-02-28', end_date: '2026-02-28' })
    expect(v.previousLabel).toBe('Previous 1 day')
  })

  it('rejects missing, malformed and inverted custom ranges', () => {
    const now = new Date(2026, 9, 14)
    expect(resolveSalesDashboardPeriod({ ...base, period: 'custom', customStart: '', customEnd: '2026-01-01' }, now).ok).toBe(false)
    expect(resolveSalesDashboardPeriod({ ...base, period: 'custom', customStart: '2026-02-30', customEnd: '2026-03-01' }, now).ok).toBe(false)
    expect(resolveSalesDashboardPeriod({ ...base, period: 'custom', customStart: '2026-03-02', customEnd: '2026-03-01' }, now).ok).toBe(false)
  })

  it('has no percentage change from a zero previous period', () => {
    expect(percentChange(100, 0)).toBeNull()
    expect(percentChange(50, 100)).toBe(-50)
  })
})
