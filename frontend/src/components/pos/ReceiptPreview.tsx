import { Card } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'

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

  return (
    <Card className="w-full h-full overflow-auto bg-white">
      {/* Receipt Paper Style */}
      <div className="p-6 font-mono text-xs max-w-[300px] mx-auto">
        {/* Header */}
        <div className="text-center space-y-1 mb-4">
          <div className="font-bold text-sm">{data.store_name}</div>
          {data.store_address && <div className="text-[10px]">{data.store_address}</div>}
          {data.store_phone && <div className="text-[10px]">{data.store_phone}</div>}
        </div>

        <Separator className="my-2" />

        {/* Receipt Info */}
        <div className="space-y-1 mb-3 text-[10px]">
          <div className="flex justify-between">
            <span>Receipt:</span>
            <span>{data.receipt_number}</span>
          </div>
          <div className="flex justify-between">
            <span>Date:</span>
            <span>{new Date(data.date).toLocaleString()}</span>
          </div>
          <div className="flex justify-between">
            <span>Cashier:</span>
            <span>{data.cashier}</span>
          </div>
        </div>

        <Separator className="my-2" />

        {/* Items */}
        <div className="space-y-2 mb-3">
          {data.items.map((item, index) => (
            <div key={index} className="space-y-0.5">
              <div className="flex justify-between">
                <span className="flex-1">{item.name}</span>
              </div>
              <div className="flex justify-between text-[10px]">
                <span>
                  {item.quantity} x ${item.price.toFixed(2)}
                </span>
                <span>${item.total.toFixed(2)}</span>
              </div>
            </div>
          ))}
        </div>

        <Separator className="my-2" />

        {/* Totals */}
        <div className="space-y-1 text-[10px]">
          <div className="flex justify-between">
            <span>Subtotal:</span>
            <span>${data.subtotal.toFixed(2)}</span>
          </div>
          <div className="flex justify-between">
            <span>Tax:</span>
            <span>${data.tax.toFixed(2)}</span>
          </div>
          {data.discount > 0 && (
            <div className="flex justify-between text-destructive">
              <span>Discount:</span>
              <span>-${data.discount.toFixed(2)}</span>
            </div>
          )}
          <Separator className="my-1" />
          <div className="flex justify-between font-bold text-xs">
            <span>TOTAL:</span>
            <span>${data.total.toFixed(2)}</span>
          </div>
        </div>

        <Separator className="my-2" />

        {/* Payment */}
        <div className="space-y-1 text-[10px]">
          <div className="flex justify-between">
            <span>Payment:</span>
            <span>{data.payment_method}</span>
          </div>
          <div className="flex justify-between">
            <span>Amount:</span>
            <span>${data.payment_amount.toFixed(2)}</span>
          </div>
          {data.change > 0 && (
            <div className="flex justify-between font-bold">
              <span>Change:</span>
              <span>${data.change.toFixed(2)}</span>
            </div>
          )}
        </div>

        {/* Footer */}
        {data.footer_message && (
          <>
            <Separator className="my-3" />
            <div className="text-center text-[10px]">{data.footer_message}</div>
          </>
        )}
      </div>
    </Card>
  )
}
