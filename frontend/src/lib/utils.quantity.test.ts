import { describe, it, expect } from 'vitest'
import { NON_WHOLE_QUANTITY_KEYS, normalizeQuantity, toWholeQuantity } from './utils'

describe('normalizeQuantity', () => {
  it('strips floating-point noise to the nearest integer', () => {
    expect(normalizeQuantity(1.0002)).toBe(1)
    expect(normalizeQuantity(1.0005)).toBe(1)
    expect(normalizeQuantity('1.0002')).toBe(1)
    expect(normalizeQuantity(2.0004)).toBe(2)
  })

  it('keeps genuine fractions (UOM box/base quantities)', () => {
    expect(normalizeQuantity(1.75)).toBe(1.75)
    expect(normalizeQuantity(0.5)).toBe(0.5)
    expect(normalizeQuantity(1.005)).toBe(1.005)
  })

  it('keeps the empty typing state', () => {
    expect(normalizeQuantity('')).toBe('')
    expect(normalizeQuantity(null)).toBe('')
    expect(normalizeQuantity(undefined)).toBe('')
  })
})

describe('toWholeQuantity (#1024: quantities are whole numbers)', () => {
  it('keeps whole numbers', () => {
    expect(toWholeQuantity(1)).toBe(1)
    expect(toWholeQuantity('2')).toBe(2)
    expect(toWholeQuantity(0)).toBe(0)
  })

  it('never yields a fractional quantity', () => {
    expect(toWholeQuantity(1.0001)).toBe(1)
    expect(toWholeQuantity('2.0001')).toBe(2)
    expect(toWholeQuantity('2.6')).toBe(3)
    expect(toWholeQuantity(0.25)).toBe(0)
  })

  it('clamps negatives to 0 and passes empty/invalid through as empty', () => {
    expect(toWholeQuantity(-3)).toBe(0)
    expect(toWholeQuantity('')).toBe('')
    expect(toWholeQuantity(null)).toBe('')
    expect(toWholeQuantity('abc')).toBe('')
  })

  it('blocks the decimal point, exponent and sign keys', () => {
    for (const key of ['.', ',', 'e', 'E', '+', '-']) expect(NON_WHOLE_QUANTITY_KEYS.has(key)).toBe(true)
    expect(NON_WHOLE_QUANTITY_KEYS.has('5')).toBe(false)
  })
})
