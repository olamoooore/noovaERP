import { describe, it, expect } from 'vitest'
import { normalizeQuantity } from './utils'

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
