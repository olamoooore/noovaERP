import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useLabelPrinting } from '@/hooks/usePrinting'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface PrintLabelButtonProps {
  product: {
    name: string
    sku: string
    price: number
    quantity?: number
  }
  variant?: 'default' | 'outline' | 'ghost' | 'secondary'
  size?: 'default' | 'sm' | 'lg' | 'icon'
  showText?: boolean
  onPrintSuccess?: () => void
  onPrintError?: (error: string) => void
}

export function PrintLabelButton({
  product,
  variant = 'outline',
  size = 'default',
  showText = true,
  onPrintSuccess,
  onPrintError,
}: PrintLabelButtonProps) {
  const { printLabel, isPrinting, canPrint, hasAgent, hasPrinter } = useLabelPrinting()

  const handlePrint = async () => {
    const success = await printLabel(product)
    
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
      return 'Please configure a label printer in settings'
    }
    if (isPrinting) {
      return 'Printing...'
    }
    return 'Print product label'
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
                <Icon icon="solar:tag-bold" className={showText ? "mr-2 h-4 w-4" : "h-4 w-4"} />
                {showText && 'Print Label'}
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
