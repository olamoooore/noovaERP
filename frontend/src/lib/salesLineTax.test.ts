import { describe, expect, it } from 'vitest'
import {
  applyLineTaxChoice,
  buildLineTaxChoices,
  lineTaxChoiceValue,
  lineTaxIdentityPayload,
  savedLineTaxChoice,
  type SalesTaxCodeOption,
} from './salesLineTax'

// Shape of GET /sales/tax-preview `options` — the tenant's own rules.
const options: SalesTaxCodeOption[] = [
  { id: 11, code: 'SG-GST-9', name: 'GST 9%', rate: 9, treatment: 'standard_rated' },
  { id: 12, code: 'SG-GST-ZERO', name: 'Zero-rated supplies', rate: 0, treatment: 'zero_rated' },
  { id: 13, code: 'SG-GST-EXEMPT', name: 'Exempt supplies', rate: 0, treatment: 'exempt' },
]

describe('#310 sales line tax choice', () => {
  it('offers every tax code from data plus the treatments no code declares', () => {
    const choices = buildLineTaxChoices(options)

    expect(choices.map((choice) => choice.value)).toEqual([
      'rule:11',
      'rule:12',
      'rule:13',
      'treatment:out_of_scope',
    ])
    expect(choices[0].label).toBe('GST 9% · Standard-rated')
    expect(choices[0].rate).toBe(9)
    expect(choices[2].label).toBe('Exempt supplies · Exempt')
    expect(choices[3].label).toBe('Out-of-scope')
  })

  it('offers zero-rated, exempt and out-of-scope as treatments when no code exists', () => {
    expect(buildLineTaxChoices([]).map((choice) => choice.value)).toEqual([
      'treatment:zero_rated',
      'treatment:exempt',
      'treatment:out_of_scope',
    ])
  })

  it('a picked exempt code sends tax_rule_id with the rule rate', () => {
    const choices = buildLineTaxChoices(options)
    const patch = applyLineTaxChoice('rule:13', choices)

    expect(patch).toEqual({ tax_rule_id: 13, tax_treatment: null, tax_rate: 0 })
    expect(lineTaxIdentityPayload(patch)).toEqual({ tax_rule_id: 13 })
    expect(lineTaxChoiceValue(patch)).toBe('rule:13')
  })

  it('a picked out-of-scope treatment sends tax_treatment and a 0% rate', () => {
    const choices = buildLineTaxChoices(options)
    const patch = applyLineTaxChoice('treatment:out_of_scope', choices)

    expect(patch).toEqual({ tax_rule_id: null, tax_treatment: 'out_of_scope', tax_rate: 0 })
    expect(lineTaxIdentityPayload(patch)).toEqual({ tax_treatment: 'out_of_scope' })
    expect(lineTaxChoiceValue(patch)).toBe('treatment:out_of_scope')
  })

  it('Auto sends no tax identity so the server resolves the line', () => {
    const patch = applyLineTaxChoice('', buildLineTaxChoices(options))

    expect(patch).toEqual({ tax_rule_id: null, tax_treatment: null })
    expect(lineTaxIdentityPayload(patch)).toEqual({})
  })

  it('a saved exempt / out-of-scope line keeps its choice; a standard or plain zero line does not', () => {
    expect(savedLineTaxChoice({ tax_rule_id: 13, tax_treatment: 'exempt' })).toEqual({ tax_rule_id: 13, tax_treatment: 'exempt' })
    expect(savedLineTaxChoice({ tax_rule_id: null, tax_treatment: 'out_of_scope' })).toEqual({ tax_rule_id: null, tax_treatment: 'out_of_scope' })
    expect(savedLineTaxChoice({ tax_rule_id: 11, tax_treatment: 'standard_rated' })).toEqual({ tax_rule_id: null, tax_treatment: null })
    expect(savedLineTaxChoice({ tax_rule_id: null, tax_treatment: 'zero_rated' })).toEqual({ tax_rule_id: null, tax_treatment: null })
  })
})
