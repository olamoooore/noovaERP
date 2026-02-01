import { forwardRef } from 'react'
import { formatCurrency } from '@/lib/utils'

interface ReceiptItem {
  name: string
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
  total: number
  paymentMethod: string
  amountPaid?: number
  change?: number
  loyaltyPointsEarned?: number
  terminal?: string
  warehouse?: string
}

interface POSReceiptProps {
  data: ReceiptData
  businessName?: string
  businessAddress?: string
  businessPhone?: string
  businessEmail?: string
  footerMessage?: string
  showBarcode?: boolean
}

export const POSReceipt = forwardRef<HTMLDivElement, POSReceiptProps>(
  ({ data, businessName = 'NoovaERP', businessAddress, businessPhone, businessEmail, footerMessage, showBarcode = true }, ref) => {
    const formatDate = (date: Date) => {
      return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      }).format(date)
    }

    // Common styles for print compatibility
    const styles = {
      container: {
        backgroundColor: '#ffffff',
        color: '#000000',
        padding: '20px',
        fontFamily: "'Courier New', Courier, monospace",
        fontSize: '12px',
        width: '320px',
        margin: '0 auto',
      },
      header: {
        textAlign: 'center' as const,
        marginBottom: '16px',
      },
      title: {
        fontSize: '20px',
        fontWeight: 'bold',
        letterSpacing: '2px',
        margin: 0,
      },
      subtext: {
        fontSize: '11px',
        marginTop: '4px',
        color: '#333',
      },
      divider: {
        borderBottom: '2px dashed #999',
        margin: '12px 0',
      },
      dividerThin: {
        borderBottom: '1px dashed #999',
        margin: '8px 0',
      },
      infoRow: {
        display: 'flex',
        justifyContent: 'space-between',
        fontSize: '11px',
        marginBottom: '4px',
      },
      label: {
        color: '#333',
      },
      value: {
        fontWeight: 'bold',
      },
      customerBox: {
        border: '1px solid #ccc',
        borderRadius: '4px',
        padding: '10px',
        marginBottom: '12px',
        fontSize: '11px',
      },
      customerLabel: {
        fontWeight: 'bold',
        marginBottom: '4px',
      },
      itemsHeader: {
        display: 'flex',
        justifyContent: 'space-between',
        fontSize: '11px',
        fontWeight: 'bold',
        borderBottom: '1px solid #ccc',
        paddingBottom: '6px',
        marginBottom: '10px',
      },
      itemRow: {
        marginBottom: '10px',
      },
      itemName: {
        fontSize: '11px',
        fontWeight: '500',
        marginBottom: '2px',
      },
      itemDetails: {
        display: 'flex',
        justifyContent: 'space-between',
        fontSize: '11px',
      },
      totalRow: {
        display: 'flex',
        justifyContent: 'space-between',
        fontSize: '11px',
        marginBottom: '4px',
      },
      grandTotal: {
        display: 'flex',
        justifyContent: 'space-between',
        fontSize: '16px',
        fontWeight: 'bold',
        borderTop: '1px solid #000',
        paddingTop: '8px',
        marginTop: '8px',
      },
      barcode: {
        display: 'flex',
        justifyContent: 'center',
        gap: '1px',
        margin: '16px 0',
      },
      barcodeBar: {
        backgroundColor: '#000',
        height: '40px',
      },
      barcodeText: {
        textAlign: 'center' as const,
        fontSize: '10px',
        marginTop: '6px',
      },
      footer: {
        textAlign: 'center' as const,
        marginTop: '16px',
        paddingTop: '12px',
        borderTop: '1px dashed #999',
      },
      thankYou: {
        fontWeight: 'bold',
        marginBottom: '6px',
      },
      powered: {
        fontSize: '9px',
        color: '#666',
        marginTop: '10px',
      },
      loyaltyBox: {
        backgroundColor: '#f3f4f6',
        borderRadius: '4px',
        padding: '10px',
        textAlign: 'center' as const,
        marginBottom: '12px',
      },
    }

    return (
      <div ref={ref} style={styles.container}>
        {/* Header */}
        <div style={styles.header}>
          <h1 style={styles.title}>{businessName}</h1>
          {businessAddress && <p style={styles.subtext}>{businessAddress}</p>}
          {businessPhone && <p style={styles.subtext}>Tel: {businessPhone}</p>}
          {businessEmail && <p style={styles.subtext}>{businessEmail}</p>}
        </div>
        
        <div style={styles.divider} />

        {/* Order Info */}
        <div style={{ marginBottom: '12px' }}>
          <div style={styles.infoRow}>
            <span style={styles.label}>Receipt #:</span>
            <span style={styles.value}>{data.receiptNumber || data.orderNumber}</span>
          </div>
          <div style={styles.infoRow}>
            <span style={styles.label}>Date:</span>
            <span>{formatDate(data.date)}</span>
          </div>
          {data.cashier && (
            <div style={styles.infoRow}>
              <span style={styles.label}>Cashier:</span>
              <span>{data.cashier}</span>
            </div>
          )}
          {data.terminal && (
            <div style={styles.infoRow}>
              <span style={styles.label}>Terminal:</span>
              <span>{data.terminal}</span>
            </div>
          )}
        </div>

        {/* Customer Info */}
        {data.customer && data.customer.name !== 'Walk-in Customer' && (
          <div style={styles.customerBox}>
            <div style={styles.customerLabel}>Customer:</div>
            <div>{data.customer.name}</div>
            {data.customer.phone && <div>Phone: {data.customer.phone}</div>}
            {data.customer.loyaltyPoints !== undefined && (
              <div style={{ marginTop: '4px' }}>
                ★ Points Balance: {data.customer.loyaltyPoints}
              </div>
            )}
          </div>
        )}

        <div style={styles.dividerThin} />

        {/* Items Header */}
        <div style={styles.itemsHeader}>
          <span style={{ flex: 1 }}>Item</span>
          <span style={{ width: '40px', textAlign: 'center' }}>Qty</span>
          <span style={{ width: '60px', textAlign: 'right' }}>Price</span>
          <span style={{ width: '70px', textAlign: 'right' }}>Total</span>
        </div>

        {/* Items */}
        <div style={{ marginBottom: '12px' }}>
          {data.items.map((item, index) => (
            <div key={index} style={styles.itemRow}>
              <div style={styles.itemName}>{item.name}</div>
              <div style={styles.itemDetails}>
                <span style={{ flex: 1 }} />
                <span style={{ width: '40px', textAlign: 'center' }}>{item.quantity}</span>
                <span style={{ width: '60px', textAlign: 'right' }}>{formatCurrency(item.price)}</span>
                <span style={{ width: '70px', textAlign: 'right' }}>{formatCurrency(item.line_total)}</span>
              </div>
            </div>
          ))}
        </div>

        <div style={styles.dividerThin} />

        {/* Totals */}
        <div style={{ marginBottom: '12px' }}>
          <div style={styles.totalRow}>
            <span>Subtotal ({data.items.reduce((s, i) => s + i.quantity, 0)} items)</span>
            <span>{formatCurrency(data.subtotal)}</span>
          </div>
          <div style={styles.totalRow}>
            <span>Tax</span>
            <span>{formatCurrency(data.taxAmount)}</span>
          </div>
          {data.discountAmount > 0 && (
            <div style={styles.totalRow}>
              <span>
                Discount 
                {data.discountType === 'percent' && data.discountValue ? ` (${data.discountValue}%)` : ''}
              </span>
              <span>-{formatCurrency(data.discountAmount)}</span>
            </div>
          )}
          <div style={styles.grandTotal}>
            <span>TOTAL</span>
            <span>{formatCurrency(data.total)}</span>
          </div>
        </div>

        <div style={styles.dividerThin} />

        {/* Payment Info */}
        <div style={{ marginBottom: '12px' }}>
          <div style={styles.totalRow}>
            <span>Payment Method:</span>
            <span style={{ fontWeight: 'bold', textTransform: 'uppercase' }}>{data.paymentMethod}</span>
          </div>
          {data.amountPaid !== undefined && (
            <div style={styles.totalRow}>
              <span>Amount Tendered:</span>
              <span>{formatCurrency(data.amountPaid)}</span>
            </div>
          )}
          {data.change !== undefined && data.change > 0 && (
            <div style={{ ...styles.totalRow, fontWeight: 'bold' }}>
              <span>Change:</span>
              <span>{formatCurrency(data.change)}</span>
            </div>
          )}
        </div>

        {/* Loyalty Points */}
        {data.loyaltyPointsEarned !== undefined && data.loyaltyPointsEarned > 0 && (
          <div style={styles.loyaltyBox}>
            <div style={{ fontSize: '11px' }}>★ LOYALTY POINTS EARNED ★</div>
            <div style={{ fontSize: '18px', fontWeight: 'bold' }}>+{data.loyaltyPointsEarned} pts</div>
          </div>
        )}

        {/* Barcode */}
        {showBarcode && (
          <div style={{ textAlign: 'center', margin: '16px 0' }}>
            <div style={styles.barcode}>
              {(data.receiptNumber || data.orderNumber).split('').map((char, i) => {
                const width = (char.charCodeAt(0) % 3) + 1
                return (
                  <div 
                    key={i} 
                    style={{ ...styles.barcodeBar, width: `${width}px` }}
                  />
                )
              })}
            </div>
            <div style={styles.barcodeText}>{data.receiptNumber || data.orderNumber}</div>
          </div>
        )}

        {/* Footer */}
        <div style={styles.footer}>
          <p style={styles.thankYou}>Thank you for your purchase!</p>
          {footerMessage && <p style={{ color: '#666', fontSize: '11px' }}>{footerMessage}</p>}
          <p style={styles.powered}>Powered by NoovaERP</p>
        </div>
      </div>
    )
  }
)

POSReceipt.displayName = 'POSReceipt'

export default POSReceipt