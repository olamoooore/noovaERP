// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { CustomerForm } from './CustomerForm'
import { emptyCustomerFormValues } from '@/lib/customerPayload'

// jsdom polyfills Radix needs at render time (same as the other smoke suites).
if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

afterEach(cleanup)

describe('CustomerForm (#437 CU9)', () => {
  it('renders the one canonical field set on the Basic tab', () => {
    render(<CustomerForm value={emptyCustomerFormValues()} onChange={() => {}} />)

    expect(screen.getByTestId('customer-form')).toBeTruthy()
    expect(screen.getByLabelText('Customer Code')).toBeTruthy()
    expect(screen.getByLabelText('Customer Type')).toBeTruthy()
    expect(screen.getByLabelText('Name *')).toBeTruthy()
    expect(screen.getByLabelText('Email')).toBeTruthy()
    expect(screen.getByLabelText('Phone')).toBeTruthy()
    expect(screen.getByLabelText('Mobile')).toBeTruthy()
    expect(screen.getByLabelText('Preferred Contact Method')).toBeTruthy()
    expect(screen.getByLabelText('Date of Birth')).toBeTruthy()
    expect(screen.getByLabelText('Gender')).toBeTruthy()
  })

  it('renders the structured billing address fields on the Addresses tab', () => {
    render(<CustomerForm value={emptyCustomerFormValues()} onChange={() => {}} />)

    fireEvent.mouseDown(screen.getByRole('tab', { name: /addresses/i }))

    expect(screen.getByLabelText('Street')).toBeTruthy()
    expect(screen.getByLabelText('Street 2')).toBeTruthy()
    expect(screen.getByLabelText('City')).toBeTruthy()
    expect(screen.getByLabelText('State / Province')).toBeTruthy()
    expect(screen.getByLabelText('Postal Code')).toBeTruthy()
    expect(screen.getByLabelText('Country (ISO code)')).toBeTruthy()
    expect(screen.getByLabelText('Shipping Address (full text)')).toBeTruthy()
  })

  it('renders credit limit, payment terms and notes on the Financial tab', () => {
    render(<CustomerForm value={emptyCustomerFormValues()} onChange={() => {}} />)

    fireEvent.mouseDown(screen.getByRole('tab', { name: /financial/i }))

    expect(screen.getByLabelText('Credit Limit')).toBeTruthy()
    expect(screen.getByLabelText('Payment Terms (days)')).toBeTruthy()
    expect(screen.getByLabelText('Notes')).toBeTruthy()
    expect(screen.getByLabelText('Opt-in for marketing communications')).toBeTruthy()
  })

  it('emits every edit through the single onChange handler', () => {
    const onChange = vi.fn()
    const value = emptyCustomerFormValues()
    render(<CustomerForm value={value} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText('Name *'), { target: { value: 'Klinik Sihat' } })

    expect(onChange).toHaveBeenCalledWith({ ...value, name: 'Klinik Sihat' })
  })

  it('normalises phone and mobile input to digits', () => {
    const onChange = vi.fn()
    const value = emptyCustomerFormValues()
    render(<CustomerForm value={value} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '+60 12-345 6789' } })

    expect(onChange).toHaveBeenCalledWith({ ...value, phone: '60123456789' })
  })

  it('compact mode is the same component over the quick capture fields only', () => {
    render(<CustomerForm compact value={emptyCustomerFormValues()} onChange={() => {}} />)

    expect(screen.getByTestId('customer-form')).toBeTruthy()
    expect(screen.getByLabelText('Name *')).toBeTruthy()
    expect(screen.getByLabelText('Phone')).toBeTruthy()
    expect(screen.getByLabelText('Email')).toBeTruthy()
    expect(screen.queryByLabelText('Customer Code')).toBeNull()
    expect(screen.queryByLabelText('Credit Limit')).toBeNull()
    expect(screen.queryByRole('tab')).toBeNull()
  })
})
