import { describe, it, expect } from 'vitest'
import { sendBundleToastDescription } from './sendBundle'

// Issue #345: the Send toast says what the bundle actually included and why
// any part was left out — never "acceptance link" when acceptance is off.

const skipped = (part: string, reason: string, message: string) => ({ part, reason, message })

describe('sendBundleToastDescription (#345)', () => {
  it('names both links when the full bundle went out', () => {
    expect(
      sendBundleToastDescription({ data: { url: 'https://a/sign/x', payment_url: 'https://pay/x', skipped: [] } }),
    ).toBe('Emailed with the acceptance link and pay-now QR.')
  })

  it('does not claim an acceptance link when acceptance is off, and says why', () => {
    const text = sendBundleToastDescription({
      data: {
        url: undefined,
        payment_url: 'https://pay/x',
        skipped: [skipped('acceptance_link', 'acceptance_disabled', 'Acceptance link not included: customer acceptance is turned off in Sales settings.')],
      },
    })
    expect(text).toBe(
      'Emailed with the pay-now link and QR. Acceptance link not included: customer acceptance is turned off in Sales settings.',
    )
  })

  it('reports a missing pay link with the reason', () => {
    const text = sendBundleToastDescription({
      data: {
        url: 'https://a/sign/x',
        payment_url: null,
        skipped: [skipped('payment_link', 'payment_link_failed', 'Pay-now link not included: the payment link could not be created.')],
      },
    })
    expect(text).toBe('Emailed with the acceptance link. Pay-now link not included: the payment link could not be created.')
  })

  it('says no links were included when both were skipped', () => {
    const text = sendBundleToastDescription({
      data: {
        payment_url: null,
        skipped: [
          skipped('acceptance_link', 'acceptance_disabled', 'Acceptance off.'),
          skipped('payment_link', 'draft', 'Draft.'),
        ],
      },
    })
    expect(text).toBe('Emailed without acceptance or pay-now links. Acceptance off. Draft.')
  })

  it('tolerates a response without a skipped list', () => {
    expect(sendBundleToastDescription(undefined)).toBe('Emailed without acceptance or pay-now links.')
  })
})
