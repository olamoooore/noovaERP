/**
 * #310 (X10): the sales line tax choice — a tax code (rule) or an explicit
 * non-charging treatment (zero-rated / exempt / out-of-scope).
 *
 * The tax codes and their rates come from GET /sales/tax-preview (`options`),
 * i.e. the tenant's own active output rules on the document date; nothing
 * here hardcodes a rate. A treatment no rule declares is still selectable so
 * an exempt or out-of-scope sale can be classified without a configured code;
 * the server resolves it (and adopts a matching 0% rule when one exists).
 */

export type LineTaxTreatment = 'standard_rated' | 'zero_rated' | 'exempt' | 'out_of_scope'

export interface SalesTaxCodeOption {
  id: number
  code: string | null
  name: string | null
  rate: number
  treatment: string | null
}

export interface LineTaxChoice {
  value: string
  label: string
  rate: number
  ruleId: number | null
  treatment: string | null
}

export interface LineTaxIdentity {
  tax_rule_id?: number | null
  tax_treatment?: string | null
}

export const TAX_TREATMENT_LABELS: Record<LineTaxTreatment, string> = {
  standard_rated: 'Standard-rated',
  zero_rated: 'Zero-rated',
  exempt: 'Exempt',
  out_of_scope: 'Out-of-scope',
}

const NON_CHARGING: LineTaxTreatment[] = ['zero_rated', 'exempt', 'out_of_scope']

export function treatmentLabel(treatment: string | null | undefined): string | null {
  if (!treatment) return null
  return TAX_TREATMENT_LABELS[treatment as LineTaxTreatment] ?? treatment
}

/** The choices the line picker offers: every tax code, then any non-charging treatment no code declares. */
export function buildLineTaxChoices(options: SalesTaxCodeOption[]): LineTaxChoice[] {
  const choices: LineTaxChoice[] = options.map((option) => {
    const kind = treatmentLabel(option.treatment)
    const name = option.name || option.code || `Tax ${option.id}`
    return {
      value: `rule:${option.id}`,
      label: `${name}${kind ? ` · ${kind}` : ''}`,
      rate: Number(option.rate) || 0,
      ruleId: option.id,
      treatment: option.treatment,
    }
  })

  const declared = new Set(options.map((option) => option.treatment))
  for (const treatment of NON_CHARGING) {
    if (!declared.has(treatment)) {
      choices.push({
        value: `treatment:${treatment}`,
        label: TAX_TREATMENT_LABELS[treatment],
        rate: 0,
        ruleId: null,
        treatment,
      })
    }
  }

  return choices
}

/** The picker value for a line ('' = let the server resolve the tax). */
export function lineTaxChoiceValue(line: LineTaxIdentity): string {
  if (line.tax_rule_id) return `rule:${line.tax_rule_id}`
  if (line.tax_treatment && NON_CHARGING.includes(line.tax_treatment as LineTaxTreatment)) {
    return `treatment:${line.tax_treatment}`
  }
  return ''
}

/** The line patch for a picked choice; '' clears the choice. */
export function applyLineTaxChoice(
  value: string,
  choices: LineTaxChoice[],
): { tax_rule_id: number | null; tax_treatment: string | null; tax_rate?: number } {
  const choice = choices.find((item) => item.value === value)
  if (!choice) return { tax_rule_id: null, tax_treatment: null }
  if (choice.ruleId !== null) return { tax_rule_id: choice.ruleId, tax_treatment: null, tax_rate: choice.rate }
  return { tax_rule_id: null, tax_treatment: choice.treatment, tax_rate: 0 }
}

/** The fields a save payload carries for the line's tax choice (none when nothing was chosen). */
export function lineTaxIdentityPayload(line: LineTaxIdentity): LineTaxIdentity {
  if (line.tax_rule_id) return { tax_rule_id: Number(line.tax_rule_id) }
  if (line.tax_treatment && NON_CHARGING.includes(line.tax_treatment as LineTaxTreatment)) {
    return { tax_treatment: line.tax_treatment }
  }
  return {}
}

/**
 * When loading a saved line, keep only an explicit non-charging
 * classification as the line's choice; a standard-rated line keeps letting
 * the server resolve its tax (a product change must not stay pinned to an
 * old code).
 */
export function savedLineTaxChoice(line: { tax_rule_id?: number | null; tax_treatment?: string | null }): LineTaxIdentity {
  const treatment = line.tax_treatment ?? null
  if (treatment && NON_CHARGING.includes(treatment as LineTaxTreatment)) {
    if (line.tax_rule_id) return { tax_rule_id: Number(line.tax_rule_id), tax_treatment: treatment }
    // A plain 0% line is zero-rated by default; only exempt / out-of-scope
    // need to travel as an explicit treatment.
    if (treatment !== 'zero_rated') return { tax_rule_id: null, tax_treatment: treatment }
  }
  return { tax_rule_id: null, tax_treatment: null }
}
