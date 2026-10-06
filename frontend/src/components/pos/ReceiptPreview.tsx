import { Card } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { QRCodeGenerator } from './QRCodeGenerator'
import { formatCurrency } from '@/lib/utils'

interface ReceiptItem {
  name: string
  quantity: number
  price: number
  total: number
}

interface ReceiptData {
  store_name: string
  store_address: string
  store_phone: string
  receipt_number: string
  date: string
  cashier: string
  items: ReceiptItem[]
  subtotal: number
  tax: number
  discount: number
  total: number
  payment_method: string
  payment_amount: number
  change: number
  footer_message: string
  // Layout settings
  paper_width?: '58mm' | '80mm'
  font_size?: 'small' | 'medium' | 'large'
  line_spacing?: 'compact' | 'normal' | 'relaxed'
  header_alignment?: 'left' | 'center' | 'right'
  footer_alignment?: 'left' | 'center' | 'right'
  // Display settings
  show_receipt_number?: boolean
  show_date?: boolean
  show_cashier?: boolean
  show_tax_breakdown?: boolean
  show_qr_code?: boolean
  qr_code_content?: 'receipt_url' | 'store_website' | 'custom'
  qr_code_custom_url?: string
  // Additional data for QR code
  receipt_id?: string
  store_website?: string
}

interface ReceiptPreviewProps {
  data: ReceiptData | null
  loading?: boolean
}

export function ReceiptPreview({ data, loading }: ReceiptPreviewProps) {
  if (loading) {
    return (
      <Card className="w-full h-full p-4 bg-muted/30 animate-pulse">
        <div className="space-y-2">
          <div className="h-4 bg-muted rounded w-3/4 mx-auto" />
          <div className="h-3 bg-muted rounded w-1/2 mx-auto" />
          <div className="h-3 bg-muted rounded w-2/3 mx-auto" />
        </div>
      </Card>
    )
  }

  if (!data) {
    return (
      <Card className="w-full h-full p-4 bg-muted/30 flex items-center justify-center">
        <p className="text-sm text-muted-foreground">Receipt preview will appear here</p>
      </Card>
    )
  }

  // Apply layout settings
  const paperWidth = data.paper_width || '80mm'
  const fontSize = data.font_size || 'medium'
  const lineSpacing = data.line_spacing || 'normal'
  const headerAlign = data.header_alignment || 'center'
  const footerAlign = data.footer_alignment || 'center'
  
  // Display settings
  const showReceiptNumber = data.show_receipt_number ?? true
  const showDate = data.show_date ?? true
  const showCashier = data.show_cashier ?? true
  const showTaxBreakdown = data.show_tax_breakdown ?? true
  const showQrCode = data.show_qr_code ?? false

  // Font size classes
  const fontSizeClasses = {
    small: 'text-[9px]',
    medium: 'text-xs',
    large: 'text-sm',
  }
  
  const fontSizeHeaderClasses = {
    small: 'text-xs',
    medium: 'text-sm',
    large: 'text-base',
  }
  
  const fontSizeDetailClasses = {
    small: 'text-[8px]',
    medium: 'text-[10px]',
    large: 'text-xs',
  }

  // Line spacing classes
  const lineSpacingClasses = {
    compact: 'space-y-0.5',
    normal: 'space-y-1',
    relaxed: 'space-y-2',
  }

  // Alignment classes
  const alignmentClasses = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
  }

  // Paper width
  const maxWidth = paperWidth === '58mm' ? 'max-w-[220px]' : 'max-w-[300px]'

  // Generate QR code value based on settings
  const getQRCodeValue = (): string => {
    const qrContent = data.qr_code_content || 'receipt_url'
    
    switch (qrContent) {
      case 'receipt_url': {
        // Generate receipt URL - in production this would be the actual receipt view URL
        const receiptId = data.receipt_id || data.receipt_number
        return `${window.location.origin}/receipts/${receiptId}`
      }
      
      case 'store_website':
        // Use store website URL
        return data.store_website || data.qr_code_custom_url || window.location.origin
      
      case 'custom':
        // Use custom URL
        return data.qr_code_custom_url || window.location.origin
      
      default:
        return window.location.origin
    }
  }

  return (
    <Card className="w-full h-full overflow-auto bg-white">
      {/* Receipt Paper Style */}
      <div className={`p-6 font-mono ${fontSizeClasses[fontSize]} ${maxWidth} mx-auto`}>
        {/* Header */}
        <div className={`${alignmentClasses[headerAlign]} ${lineSpacingClasses[lineSpacing]} mb-4`}>
          <div className={`font-bold ${fontSizeHeaderClasses[fontSize]}`}>{data.store_name}</div>
          {data.store_address && <div className={fontSizeDetailClasses[fontSize]}>{data.store_address}</div>}
          {data.store_phone && <div className={fontSizeDetailClasses[fontSize]}>{data.store_phone}</div>}
        </div>

        <Separator className="my-2" />

        {/* Receipt Info */}
        <div className={`${lineSpacingClasses[lineSpacing]} mb-3 ${fontSizeDetailClasses[fontSize]}`}>
          {showReceiptNumber && (
            <div className="flex justify-between">
              <span>Receipt:</span>
              <span>{data.receipt_number}</span>
            </div>
          )}
          {showDate && (
            <div className="flex justify-between">
              <span>Date:</span>
              <span>{new Date(data.date).toLocaleString()}</span>
            </div>
          )}
          {showCashier && (
            <div className="flex justify-between">
              <span>Cashier:</span>
              <span>{data.cashier}</span>
            </div>
          )}
        </div>

        <Separator className="my-2" />

        {/* Items */}
        <div className={`${lineSpacingClasses[lineSpacing === 'compact' ? 'normal' : lineSpacing]} mb-3`}>
          {data.items.map((item, index) => {
            const price = typeof item.price === 'number' ? item.price : parseFloat(item.price) || 0
            const total = typeof item.total === 'number' ? item.total : parseFloat(item.total) || 0
            
            return (
              <div key={index} className="space-y-0.5">
                <div className="flex justify-between">
                  <span className="flex-1">{item.name}</span>
                </div>
                <div className={`flex justify-between ${fontSizeDetailClasses[fontSize]}`}>
                  <span>
                    {item.quantity} x {formatCurrency(price)}
                  </span>
                  <span>{formatCurrency(total)}</span>
                </div>
              </div>
            )
          })}
        </div>

        <Separator className="my-2" />

        {/* Totals */}
        <div className={`${lineSpacingClasses[lineSpacing]} ${fontSizeDetailClasses[fontSize]}`}>
          <div className="flex justify-between">
            <span>Subtotal:</span>
            <span>{formatCurrency(Number(data.subtotal) || 0)}</span>
          </div>
          {showTaxBreakdown && (
            <div className="flex justify-between">
              <span>Tax:</span>
              <span>{formatCurrency(Number(data.tax) || 0)}</span>
            </div>
          )}
          {data.discount > 0 && (
            <div className="flex justify-between text-destructive">
              <span>Discount:</span>
              <span>-{formatCurrency(Number(data.discount) || 0)}</span>
            </div>
          )}
          <Separator className="my-1" />
          <div className={`flex justify-between font-bold ${fontSizeClasses[fontSize]}`}>
            <span>TOTAL:</span>
            <span>{formatCurrency(Number(data.total) || 0)}</span>
          </div>
        </div>

        <Separator className="my-2" />

        {/* Payment */}
        <div className={`${lineSpacingClasses[lineSpacing]} ${fontSizeDetailClasses[fontSize]}`}>
          <div className="flex justify-between">
            <span>Payment:</span>
            <span>{data.payment_method}</span>
          </div>
          <div className="flex justify-between">
            <span>Amount:</span>
            <span>{formatCurrency(Number(data.payment_amount) || 0)}</span>
          </div>
          {data.change > 0 && (
            <div className="flex justify-between font-bold">
              <span>Change:</span>
              <span>{formatCurrency(Number(data.change) || 0)}</span>
            </div>
          )}
        </div>

        {/* QR Code */}
        {showQrCode && (
          <>
            <Separator className="my-3" />
            <div className="flex flex-col items-center space-y-2">
              <QRCodeGenerator
                value={getQRCodeValue()}
                size={96}
                className="rounded"
              />
              <div className={`text-center ${fontSizeDetailClasses[fontSize]}`}>
                Scan for digital receipt
              </div>
            </div>
          </>
        )}

        {/* Footer */}
        {data.footer_message && (
          <>
            <Separator className="my-3" />
            <div className={`${alignmentClasses[footerAlign]} ${fontSizeDetailClasses[fontSize]}`}>
              {data.footer_message}
            </div>
          </>
        )}
      </div>
    </Card>
  )
}
