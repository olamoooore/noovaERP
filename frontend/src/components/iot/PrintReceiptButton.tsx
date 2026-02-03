import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useReceiptPrinting } from '@/hooks/usePrinting'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface PrintReceiptButtonProps {
  order: {
    id: number
    items: Array<{
      name: string
      quantity: number
      price: number
    }>
    total: number
    tax: number
    payment_method: string
    store_name?: string
    customer_name?: string
  }
  variant?: 'default' | 'outline' | 'ghost' | 'secondary'
  size?: 'default' | 'sm' | 'lg' | 'icon'
  showText?: boolean
  onPrintSuccess?: () => void
  onPrintError?: (error: string) => void
}

export function PrintReceiptButton({
  order,
  variant = 'outline',
  size = 'default',
  showText = true,
  onPrintSuccess,
  onPrintError,
}: PrintReceiptButtonProps) {
  const { printReceipt, isPrinting, canPrint, hasAgent, hasPrinter } = useReceiptPrinting()

  const handlePrint = async () => {
    const success = await printReceipt(order)
    
    if (success && onPrintSuccess) {
      onPrintSuccess()
    } else if (!success && onPrintError) {
      onPrintError('Print failed')
    }
  }

  const getTooltipMessage = () => {
    if (!hasAgent) {
      return 'Please link a UMP agent in settings'
    }
    if (!hasPrinter) {
      return 'Please configure a receipt printer in settings'
    }
    if (isPrinting) {
      return 'Printing...'
    }
    return 'Print order receipt'
  }

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant={variant}
            size={size}
            onClick={handlePrint}
            disabled={!canPrint || isPrinting}
          >
            {isPrinting ? (
              <>
                <Icon icon="solar:restart-circle-bold" className={showText ? "mr-2 h-4 w-4 animate-spin" : "h-4 w-4 animate-spin"} />
                {showText && 'Printing...'}
              </>
            ) : (
              <>
                <Icon icon="solar:printer-bold" className={showText ? "mr-2 h-4 w-4" : "h-4 w-4"} />
                {showText && 'Print Receipt'}
              </>
            )}
          </Button>
        </TooltipTrigger>
        <TooltipContent>
          <p>{getTooltipMessage()}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
