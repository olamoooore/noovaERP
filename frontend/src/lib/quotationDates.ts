import { addCalendarDays } from './dateOnly';

export const DEFAULT_QUOTE_VALIDITY_DAYS = 30;

/**
 * #297 (Q11): Valid Until follows the quote date plus the validity period,
 * unless the operator edited Valid Until themselves — then their value is
 * kept. An empty quote date keeps the current value too.
 */
export function followValidUntil(
  quotationDate: string,
  currentValidUntil: string,
  manuallyEdited: boolean,
  validityDays: number = DEFAULT_QUOTE_VALIDITY_DAYS,
): string {
  if (manuallyEdited || !quotationDate) {
    return currentValidUntil;
  }

  return addCalendarDays(quotationDate, validityDays);
}
