// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import {
  extractValidationErrors,
  focusFirstFieldError,
  lineFieldKey,
  payloadRowIndexes,
  remapLineErrors,
} from './serverValidation'

// #318 / #398: one mapper from Laravel 422 `errors` keys to form fields and
// grid cells, shared by the quotation, sales order and invoice forms.

const laravel422 = (errors: Record<string, string[]>) => ({
  response: { status: 422, data: { message: 'The given data was invalid.', errors } },
})

describe('extractValidationErrors', () => {
  it('keeps the first message per Laravel key, including line cell keys', () => {
    expect(extractValidationErrors(laravel422({
      customer_id: ['The customer field is required.', 'second'],
      'lines.2.quantity': ['The quantity must be at least 0.0001.'],
    }))).toEqual({
      customer_id: 'The customer field is required.',
      'lines.2.quantity': 'The quantity must be at least 0.0001.',
    })
  })

  it('ignores anything that is not a 422 with field errors', () => {
    expect(extractValidationErrors({ response: { status: 500, data: { errors: { a: ['x'] } } } })).toBeNull()
    expect(extractValidationErrors({ response: { status: 422, data: { message: 'nope' } } })).toBeNull()
    expect(extractValidationErrors(new Error('network'))).toBeNull()
    expect(extractValidationErrors(laravel422({ customer_id: [] }))).toBeNull()
  })
})

describe('remapLineErrors', () => {
  it('maps payload line indexes back to the on-screen grid rows', () => {
    // Row 0 is a blank starter row (not sent); payload line 0 is grid row 1.
    expect(remapLineErrors({
      customer_id: 'Required',
      'lines.0.quantity': 'Too small',
      'lines.1': 'Bad line',
    }, [1, 3])).toEqual({
      customer_id: 'Required',
      'lines.1.quantity': 'Too small',
      'lines.3': 'Bad line',
    })
  })

  it('builds the row map from a payload filter', () => {
    const rows = [{ keep: false }, { keep: true }, { keep: false }, { keep: true }]
    expect(payloadRowIndexes(rows, (row) => row.keep)).toEqual([1, 3])
    expect(lineFieldKey(2, 'quantity')).toBe('lines.2.quantity')
  })
})

describe('focusFirstFieldError', () => {
  afterEach(() => { document.body.innerHTML = '' })

  it('scrolls to and focuses the first invalid field in document order', () => {
    document.body.innerHTML = `
      <div data-field-key="customer_id"><button type="button">Customer</button></div>
      <div data-field-key="lines.0.product_id"><button type="button">Product</button></div>
      <div data-field-key="lines.0.quantity"><input aria-label="Quantity" /></div>
    `
    const slot = document.querySelector('[data-field-key="lines.0.quantity"]') as HTMLElement
    const scroll = vi.fn()
    slot.scrollIntoView = scroll

    const target = focusFirstFieldError({ 'lines.0.quantity': 'Too small' })

    expect(target).toBe(document.querySelector('input'))
    expect(document.activeElement).toBe(document.querySelector('input'))
    expect(scroll).toHaveBeenCalled()
  })

  it('falls back to the first cell of the line when the field has no cell', () => {
    document.body.innerHTML = `
      <div data-field-key="lines.1.product_id"><button type="button">Product</button></div>
      <div data-field-key="lines.1.quantity"><input aria-label="Quantity" /></div>
    `
    focusFirstFieldError({ 'lines.1.income_account_id': 'Invalid account' })
    expect(document.activeElement?.textContent).toBe('Product')
  })

  it('returns null when nothing on screen matches', () => {
    document.body.innerHTML = '<input />'
    expect(focusFirstFieldError({ unknown: 'x' })).toBeNull()
  })
})
