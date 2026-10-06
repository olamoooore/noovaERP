import { forwardRef } from 'react'
import { formatCurrency, getCurrencySymbol } from '@/lib/utils'

interface ReceiptItem {
  name: string
  variant?: string
  quantity: number
  price: number
  line_total: number
}

interface ReceiptData {
  orderNumber: string
  receiptNumber?: string
  date: Date
  cashier?: string
  customer?: {
    name: string
    phone?: string
    email?: string
    loyaltyPoints?: number
  }
  items: ReceiptItem[]
  subtotal: number
  taxAmount: number
  discountAmount: number
  discountType?: 'fixed' | 'percent'
  discountValue?: number
  coupon?: {
    code: string
    discountAmount: number
    discountType: 'fixed' | 'percent'
    discountValue: number
  }
  total: number
  paymentMethod: string
  amountPaid?: number
  change?: number
  loyaltyPointsEarned?: number
  loyaltyPointsUsed?: number
  loyaltyDiscountAmount?: number
  terminal?: string
  warehouse?: string
}

interface POSReceiptProps {
  data: ReceiptData
  businessName?: string
  businessAddresses?: string[]
  businessAddress?: string
  businessPhone?: string
  businessEmail?: string
  instagram?: string
  facebook?: string
  whatsapp?: string
  businessWebsite?: string
  gstRegNo?: string
  footerMessage?: string
  showBarcode?: boolean
}

export const POSReceipt = forwardRef<HTMLDivElement, POSReceiptProps>(
  ({ data, businessName = 'Point of Sale', businessAddresses, businessAddress, businessPhone, whatsapp, instagram, facebook, businessWebsite, gstRegNo, footerMessage }, ref) => {

    const fmt = (n: number) => formatCurrency(Number(n) || 0)

    const formatDate = (date: Date) => {
      const m = date.toLocaleString('en-US', { month: 'short' })
      const d = String(date.getDate()).padStart(2, '0')
      const y = date.getFullYear()
      const time = date.toLocaleString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })
      return `${m}-${d}-${y} ${time}`
    }

    const addresses: string[] = businessAddresses?.length
      ? businessAddresses
      : businessAddress
      ? [businessAddress]
      : []

    const whatsappDigits = (whatsapp || businessPhone || '').replace(/\D/g, '')
    const qrPayload = whatsappDigits
      ? `https://wa.me/${whatsappDigits}`
      : data.receiptNumber || data.orderNumber
    const primaryQrUrl = `https://quickchart.io/qr?size=120&text=${encodeURIComponent(qrPayload)}`
    const fallbackQrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=${encodeURIComponent(qrPayload)}`

    const paymentLabel = `${(data.paymentMethod || 'CASH').toUpperCase()} ${getCurrencySymbol()}`
    const itemQty = data.items.reduce((s, i) => s + i.quantity, 0)
    const taxRate = data.taxAmount > 0 && data.subtotal > 0
      ? Math.round(data.taxAmount / (data.total - data.taxAmount) * 100)
      : 9
    const taxable = Math.max(data.total - data.taxAmount, 0)
    const cashier = data.cashier ? data.cashier.substring(0, 2).toUpperCase() : 'ST'

    const s: Record<string, React.CSSProperties> = {
      root: { fontFamily: "'Courier New', Courier, monospace", fontSize: 13, lineHeight: 1.4, padding: '12px 14px', width: '100%', color: '#000', backgroundColor: '#fff' },
      center: { textAlign: 'center' },
      storeName: { fontSize: 16, fontWeight: 'bold', marginBottom: 3 },
      headerLine: { fontSize: 12, marginBottom: 2 },
      bold: { fontWeight: 'bold' },
      dash: { borderBottom: '1px dashed #000', margin: '8px 0' },
      row: { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 3 },
      right: { minWidth: 64, textAlign: 'right', flexShrink: 0 },
      // item layout: qty col | name col | amount col (matches physical receipt)
      itemRow: { display: 'flex', alignItems: 'flex-start', marginBottom: 4 },
      itemQtyCol: { width: 18, flexShrink: 0 },
      itemNameCol: { flex: 1, wordBreak: 'break-word' as const, paddingRight: 6 },
      itemAmtCol: { minWidth: 52, textAlign: 'right' as const, flexShrink: 0 },
      variantRow: { display: 'flex', marginBottom: 4, paddingLeft: 18 },
    }

    return (
      <div ref={ref} style={s.root}>
        {/* Header */}
        <div style={s.center}>
          <div style={s.storeName}>{businessName}</div>
          {addresses.map((a, i) => <div key={i} style={s.headerLine}>{a}</div>)}
          {(whatsapp || businessPhone) && <div style={s.headerLine}>WhatsApp: {whatsapp || businessPhone}</div>}
          {instagram && <div style={s.headerLine}>Instagram: {instagram}</div>}
          {businessWebsite && <div style={s.headerLine}>Online Shop: {businessWebsite}</div>}
          {facebook && <div style={s.headerLine}>Facebook: {facebook}</div>}
          {gstRegNo && <div style={s.headerLine}>GST REG NO: {gstRegNo}</div>}
          <div style={{ ...s.bold, marginTop: 4, fontSize: 13 }}>** Duplicate Copy-1 **</div>
        </div>

        <div style={s.dash} />

        {/* Receipt number */}
        <div style={{ ...s.bold, marginBottom: 6, fontSize: 13 }}>Receipt: {data.receiptNumber || data.orderNumber}</div>

        {/* Items — qty | name | amount, variant indented below */}
        {data.items.map((item, i) => (
          <div key={i}>
            <div style={s.itemRow}>
              <span style={s.itemQtyCol}>{item.quantity}</span>
              <span style={s.itemNameCol}>{item.name}</span>
              <span style={s.itemAmtCol}>{fmt(item.line_total)}</span>
            </div>
            {item.variant && (
              <div style={s.variantRow}>
                <span style={{ flex: 1, color: '#222' }}>{item.variant}</span>
              </div>
            )}
          </div>
        ))}

        <div style={s.dash} />

        {/* Subtotal / Discount / Total / Payment */}
        <div style={s.row}><span>SUBTOTAL</span><span style={s.right}>{fmt(data.subtotal)}</span></div>
        {data.discountAmount > 0 && (
          <div style={s.row}><span>DISCOUNT</span><span style={s.right}>-{fmt(data.discountAmount)}</span></div>
        )}
        <div style={{ ...s.row, ...s.bold }}><span>TOTAL</span><span style={s.right}>{fmt(data.total)}</span></div>
        <div style={s.row}><span>{paymentLabel}</span><span style={s.right}>{fmt(data.amountPaid ?? data.total)}</span></div>

        <div style={s.dash} />

        {/* Terminal / Cashier / Date */}
        <div style={{ ...s.row, marginBottom: 2 }}>
          <span>Item Qty: {itemQty}</span>
          <span>Svc Qty: 0</span>
        </div>
        <div style={{ marginBottom: 2 }}>Term: {data.terminal ?? '-'} Cashier: {cashier}</div>
        <div>Date: {formatDate(data.date)}</div>

        {/* GST section */}
        {data.taxAmount > 0 && (
          <>
            <div style={s.dash} />
            <div style={s.row}><span>GST {taxRate}%</span><span style={s.right}>{fmt(data.taxAmount)}</span></div>
            <div style={s.row}><span>Taxable</span><span style={s.right}>{fmt(taxable)}</span></div>
          </>
        )}

        <div style={s.dash} />

        {/* Footer — centered */}
        <div style={{ textAlign: 'center' }}>
          <div>Items sold are non</div>
          <div>refundable/exchangeable</div>
          <div>/returnable</div>
          {footerMessage && <div style={{ marginTop: 3 }}>{footerMessage}</div>}
          <div>Thank you!</div>
          <div>Please come again!</div>
          {qrPayload && (
            <div style={{ marginTop: 12, marginBottom: 6, textAlign: 'center' }}>
              <img
                src={primaryQrUrl}
                alt="WhatsApp QR"
                onError={(event) => {
                  const img = event.currentTarget
                  if (img.src !== fallbackQrUrl) {
                    img.src = fallbackQrUrl
                  }
                }}
                style={{ width: 90, height: 90, display: 'block', margin: '0 auto' }}
              />
              <div style={{ fontSize: 10, marginTop: 4 }}>Scan to contact us on WhatsApp</div>
            </div>
          )}
        </div>
      </div>
    )
  }
)

POSReceipt.displayName = 'POSReceipt'

export default POSReceipt