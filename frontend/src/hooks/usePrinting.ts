import { useState } from 'react'
import { useToast } from '@/hooks/use-toast'
import {
  sendPrintJob,
  getLinkedAgentId,
  isAgentLinked,
  type LabelPrintData,
  type ReceiptPrintData,
} from '@/services/printService'
import {
  getSelectedLabelPrinter,
  getSelectedReceiptPrinter,
  hasLabelPrinterConfigured,
  hasReceiptPrinterConfigured,
} from '@/components/iot/PrinterSetup'

/**
 * Custom hook for label printing functionality
 * 
 * @example
 * const { printLabel, isPrinting, canPrint } = useLabelPrinting()
 * 
 * <Button onClick={() => printLabel(product)} disabled={!canPrint || isPrinting}>
 *   Print Label
 * </Button>
 */
export function useLabelPrinting() {
  const [isPrinting, setIsPrinting] = useState(false)
  const { toast } = useToast()

  const canPrint = isAgentLinked() && hasLabelPrinterConfigured()

  const printLabel = async (product: {
    name: string
    sku: string
    price: number
    quantity?: number
  }) => {
    // Validation
    if (!isAgentLinked()) {
      toast({
        title: 'No Agent Linked',
        description: 'Please link a UMP agent in settings first',
        variant: 'destructive',
      })
      return false
    }

    if (!hasLabelPrinterConfigured()) {
      toast({
        title: 'No Label Printer',
        description: 'Please configure a label printer in settings',
        variant: 'destructive',
      })
      return false
    }

    const agentId = getLinkedAgentId()
    const labelPrinter = getSelectedLabelPrinter()

    if (!agentId || !labelPrinter) {
      toast({
        title: 'Configuration Error',
        description: 'Printer configuration is incomplete',
        variant: 'destructive',
      })
      return false
    }

    setIsPrinting(true)

    try {
      // Construct label data
      const labelData: LabelPrintData = {
        productName: product.name,
        sku: product.sku,
        price: `RM ${product.price.toFixed(2)}`,
        quantity: product.quantity || 1,
      }

      // Send print job
      const response = await sendPrintJob(agentId, 'label', labelData)

      if (response.success) {
        toast({
          title: 'Print Successful',
          description: `Label for "${product.name}" has been sent to printer`,
        })
        return true
      } else {
        toast({
          title: 'Print Failed',
          description: response.message || 'Failed to print label',
          variant: 'destructive',
        })
        return false
      }
    } catch (error) {
      console.error('Print label error:', error)
      toast({
        title: 'Print Error',
        description: 'An unexpected error occurred while printing',
        variant: 'destructive',
      })
      return false
    } finally {
      setIsPrinting(false)
    }
  }

  return {
    printLabel,
    isPrinting,
    canPrint,
    hasAgent: isAgentLinked(),
    hasPrinter: hasLabelPrinterConfigured(),
  }
}

/**
 * Custom hook for receipt printing functionality
 * 
 * @example
 * const { printReceipt, isPrinting, canPrint } = useReceiptPrinting()
 * 
 * <Button onClick={() => printReceipt(order)} disabled={!canPrint || isPrinting}>
 *   Print Receipt
 * </Button>
 */
export function useReceiptPrinting() {
  const [isPrinting, setIsPrinting] = useState(false)
  const { toast } = useToast()

  const canPrint = isAgentLinked() && hasReceiptPrinterConfigured()

  const printReceipt = async (order: {
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
  }) => {
    // Validation
    if (!isAgentLinked()) {
      toast({
        title: 'No Agent Linked',
        description: 'Please link a UMP agent in settings first',
        variant: 'destructive',
      })
      return false
    }

    if (!hasReceiptPrinterConfigured()) {
      toast({
        title: 'No Receipt Printer',
        description: 'Please configure a receipt printer in settings',
        variant: 'destructive',
      })
      return false
    }

    const agentId = getLinkedAgentId()
    const receiptPrinter = getSelectedReceiptPrinter()

    if (!agentId || !receiptPrinter) {
      toast({
        title: 'Configuration Error',
        description: 'Printer configuration is incomplete',
        variant: 'destructive',
      })
      return false
    }

    setIsPrinting(true)

    try {
      // Construct receipt data
      const receiptData: ReceiptPrintData = {
        order_id: order.id,
        items: order.items,
        total: order.total,
        tax: order.tax,
        payment_method: order.payment_method,
        store_name: order.store_name,
        customer_name: order.customer_name,
      }

      // Send print job
      const response = await sendPrintJob(agentId, 'receipt', receiptData)

      if (response.success) {
        toast({
          title: 'Print Successful',
          description: `Receipt for order #${order.id} has been sent to printer`,
        })
        return true
      } else {
        toast({
          title: 'Print Failed',
          description: response.message || 'Failed to print receipt',
          variant: 'destructive',
        })
        return false
      }
    } catch (error) {
      console.error('Print receipt error:', error)
      toast({
        title: 'Print Error',
        description: 'An unexpected error occurred while printing',
        variant: 'destructive',
      })
      return false
    } finally {
      setIsPrinting(false)
    }
  }

  return {
    printReceipt,
    isPrinting,
    canPrint,
    hasAgent: isAgentLinked(),
    hasPrinter: hasReceiptPrinterConfigured(),
  }
}
