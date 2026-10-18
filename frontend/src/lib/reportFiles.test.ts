import { describe, expect, it } from 'vitest'
import { downloadReportDocx, downloadReportXlsx } from './reportFiles'

const rows = [
  ['Account', 'Debit', 'Credit'],
  ['400100 Sales Revenue', '0.00', '1,000.00'],
  ['510000 Cost of Goods Sold', '250.00', '0.00'],
]

const zipSignature = async (blob: Blob) => {
  const bytes = new Uint8Array(await blob.arrayBuffer())
  return [bytes[0], bytes[1], bytes[2], bytes[3]]
}

describe('report excel/word exports', () => {
  it('produces a non-empty xlsx (zip container) blob', async () => {
    const blob = await downloadReportXlsx(rows, { filename: 'gl', title: 'General Ledger' })

    expect(blob).toBeTruthy()
    expect(blob!.size).toBeGreaterThan(0)
    expect(blob!.type).toContain('spreadsheetml')
    // Real .xlsx files are zip archives → "PK\u0003\u0004"
    expect(await zipSignature(blob!)).toEqual([0x50, 0x4b, 0x03, 0x04])
  })

  it('produces a non-empty docx (zip container) blob', async () => {
    const blob = await downloadReportDocx(rows, {
      filename: 'gl',
      title: 'General Ledger',
      subtitle: 'All accounts',
      companyName: 'ACME Pte Ltd',
    })

    expect(blob).toBeTruthy()
    expect(blob!.size).toBeGreaterThan(0)
    expect(blob!.type).toContain('wordprocessingml')
    expect(await zipSignature(blob!)).toEqual([0x50, 0x4b, 0x03, 0x04])
  })

  it('returns null when there is nothing to export', async () => {
    expect(await downloadReportXlsx([], { filename: 'gl', title: 'General Ledger' })).toBeNull()
    expect(await downloadReportDocx([], { filename: 'gl', title: 'General Ledger' })).toBeNull()
  })
})
