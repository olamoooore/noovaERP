import { describe, expect, it } from 'vitest'
import { traceabilityParams } from './traceability'

describe('traceabilityParams', () => {
  it('maps a lot search to the lot_number query parameter', () => {
    expect(traceabilityParams({ type: 'lot', value: 'LOT-583-A' })).toEqual({ lot_number: 'LOT-583-A' })
  })

  it('maps a serial search to the serial_number query parameter', () => {
    expect(traceabilityParams({ type: 'serial', value: 'SN-583-0001' })).toEqual({ serial_number: 'SN-583-0001' })
  })

  it('returns no parameters before a search has been submitted', () => {
    expect(traceabilityParams(null)).toEqual({})
  })
})
