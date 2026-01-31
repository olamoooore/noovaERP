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

    return (
      <div 
        ref={ref}
        className="bg-white text-black p-6 font-mono text-sm w-[320px] mx-auto"
        style={{ fontFamily: "'Courier New', Courier, monospace" }}
      >
        {/* Header */}
        <div className="text-center mb-4">
          <h1 className="text-xl font-bold tracking-wider">{businessName}</h1>
          {businessAddress && <p className="text-xs mt-1">{businessAddress}</p>}
          {businessPhone && <p className="text-xs">Tel: {businessPhone}</p>}
          {businessEmail && <p className="text-xs">{businessEmail}</p>}
          <div className="border-b-2 border-dashed border-gray-400 my-3" />
        </div>

        {/* Order Info */}
        <div className="mb-3 text-xs space-y-1">
          <div className="flex justify-between">
            <span>Receipt #:</span>
            <span className="font-bold">{data.receiptNumber || data.orderNumber}</span>
          </div>
          <div className="flex justify-between">
            <span>Date:</span>
            <span>{formatDate(data.date)}</span>
          </div>
          {data.cashier && (
            <div className="flex justify-between">
              <span>Cashier:</span>
              <span>{data.cashier}</span>
            </div>
          )}
          {data.terminal && (
            <div className="flex justify-between">
              <span>Terminal:</span>
              <span>{data.terminal}</span>
            </div>
          )}
        </div>

        {/* Customer Info */}
        {data.customer && data.customer.name !== 'Walk-in Customer' && (
          <div className="mb-3 text-xs border border-gray-300 rounded p-2">
            <div className="font-bold mb-1">Customer:</div>
            <div>{data.customer.name}</div>
            {data.customer.phone && <div>Phone: {data.customer.phone}</div>}
            {data.customer.loyaltyPoints !== undefined && (
              <div className="mt-1 text-xs">
                ★ Points Balance: {data.customer.loyaltyPoints}
              </div>
            )}
          </div>
        )}

        <div className="border-b border-dashed border-gray-400 my-2" />

        {/* Items */}
        <div className="mb-3">
          <div className="flex justify-between text-xs font-bold border-b border-gray-300 pb-1 mb-2">
            <span className="flex-1">Item</span>
            <span className="w-12 text-center">Qty</span>
            <span className="w-16 text-right">Price</span>
            <span className="w-20 text-right">Total</span>
          </div>
          {data.items.map((item, index) => (
            <div key={index} className="mb-2">
              <div className="text-xs truncate font-medium">{item.name}</div>
              <div className="flex justify-between text-xs">
                <span className="flex-1" />
                <span className="w-12 text-center">{item.quantity}</span>
                <span className="w-16 text-right">{formatCurrency(item.price)}</span>
                <span className="w-20 text-right">{formatCurrency(item.line_total)}</span>
              </div>
            </div>
          ))}
        </div>

        <div className="border-b border-dashed border-gray-400 my-2" />

        {/* Totals */}
        <div className="space-y-1 text-xs mb-3">
          <div className="flex justify-between">
            <span>Subtotal ({data.items.reduce((s, i) => s + i.quantity, 0)} items)</span>
            <span>{formatCurrency(data.subtotal)}</span>
          </div>
          <div className="flex justify-between">
            <span>Tax</span>
            <span>{formatCurrency(data.taxAmount)}</span>
          </div>
          {data.discountAmount > 0 && (
            <div className="flex justify-between">
              <span>
                Discount 
                {data.discountType === 'percent' && data.discountValue ? ` (${data.discountValue}%)` : ''}
              </span>
              <span>-{formatCurrency(data.discountAmount)}</span>
            </div>
          )}
          <div className="border-t border-gray-400 pt-1 mt-1" />
          <div className="flex justify-between font-bold text-base">
            <span>TOTAL</span>
            <span>{formatCurrency(data.total)}</span>
          </div>
        </div>

        <div className="border-b border-dashed border-gray-400 my-2" />

        {/* Payment Info */}
        <div className="space-y-1 text-xs mb-3">
          <div className="flex justify-between">
            <span>Payment Method:</span>
            <span className="uppercase font-bold">{data.paymentMethod}</span>
          </div>
          {data.amountPaid !== undefined && (
            <div className="flex justify-between">
              <span>Amount Tendered:</span>
              <span>{formatCurrency(data.amountPaid)}</span>
            </div>
          )}
          {data.change !== undefined && data.change > 0 && (
            <div className="flex justify-between font-bold">
              <span>Change:</span>
              <span>{formatCurrency(data.change)}</span>
            </div>
          )}
        </div>

        {/* Loyalty Points */}
        {data.loyaltyPointsEarned !== undefined && data.loyaltyPointsEarned > 0 && (
          <div className="bg-gray-100 rounded p-2 mb-3 text-center">
            <div className="text-xs">★ LOYALTY POINTS EARNED ★</div>
            <div className="text-lg font-bold">+{data.loyaltyPointsEarned} pts</div>
          </div>
        )}

        {/* Barcode */}
        {showBarcode && (
          <div className="text-center my-4">
            <div className="inline-flex flex-col items-center">
              {/* Simple barcode representation using CSS */}
              <div className="flex gap-[1px]">
                {(data.receiptNumber || data.orderNumber).split('').map((char, i) => {
                  const width = (char.charCodeAt(0) % 3) + 1
                  return (
                    <div 
                      key={i} 
                      className="bg-black" 
                      style={{ width: `${width}px`, height: '40px' }}
                    />
                  )
                })}
              </div>
              <div className="text-xs mt-1">{data.receiptNumber || data.orderNumber}</div>
            </div>
          </div>
        )}

        {/* Footer */}
        <div className="text-center mt-4 text-xs space-y-2">
          <div className="border-t border-dashed border-gray-400 pt-3" />
          <p className="font-bold">Thank you for your purchase!</p>
          {footerMessage && <p className="text-gray-600">{footerMessage}</p>}
          <p className="text-gray-500 text-[10px]">
            Powered by NoovaERP
          </p>
        </div>
      </div>
    )
  }
)

POSReceipt.displayName = 'POSReceipt'

export default POSReceipt
