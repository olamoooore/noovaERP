/**
 * Issue #345: POST /invoices/{id}/send-bundle reports which parts it left out
 * (`data.skipped`, each with a readable `message`). The toast describes only
 * what was actually emailed, then why anything was left out.
 */
export interface SendBundleSkippedPart {
  part: 'acceptance_link' | 'payment_link' | string
  reason: string
  message: string
}

interface SendBundleResponse {
  data?: {
    url?: string | null
    payment_url?: string | null
    skipped?: SendBundleSkippedPart[]
  }
}

export function sendBundleToastDescription(response: SendBundleResponse | undefined | null): string {
  const data = response?.data
  const hasAcceptance = Boolean(data?.url)
  const hasPayment = Boolean(data?.payment_url)

  const included = hasAcceptance && hasPayment
    ? 'Emailed with the acceptance link and pay-now QR.'
    : hasAcceptance
      ? 'Emailed with the acceptance link.'
      : hasPayment
        ? 'Emailed with the pay-now link and QR.'
        : 'Emailed without acceptance or pay-now links.'

  const skipped = (data?.skipped ?? []).map((entry) => entry.message).filter(Boolean)

  return [included, ...skipped].join(' ')
}
