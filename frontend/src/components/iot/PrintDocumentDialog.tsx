/**
 * Print Document Dialog Component
 * 
 * Allows users to select and upload documents (PDF, Word, Excel) to print
 * via the UMP API to connected printers.
 */

import { useState, useRef } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useToast } from '@/hooks/use-toast'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import universalIoTService, { UMPDevice } from '@/services/UniversalIoTService'

interface PrintDocumentDialogProps {
  isOpen: boolean
  onClose: () => void
  device: UMPDevice
  agentId: string
}

export function PrintDocumentDialog({ isOpen, onClose, device, agentId }: PrintDocumentDialogProps) {
  const { toast } = useToast()
  const fileInputRef = useRef<HTMLInputElement>(null)
  
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [isPrinting, setIsPrinting] = useState(false)

  // Allowed file types
  const allowedTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ]

  const allowedExtensions = '.pdf,.doc,.docx,.xls,.xlsx'

  /**
   * Handle file selection
   */
  const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (!file) {
      setSelectedFile(null)
      return
    }

    // Validate file type
    if (!allowedTypes.includes(file.type)) {
      toast({
        title: 'Invalid File Type',
        description: 'Please select a PDF, Word, or Excel file',
        variant: 'destructive',
      })
      event.target.value = ''
      return
    }

    // Validate file size (max 10MB)
    const maxSize = 10 * 1024 * 1024 // 10MB
    if (file.size > maxSize) {
      toast({
        title: 'File Too Large',
        description: 'File size must be less than 10MB',
        variant: 'destructive',
      })
      event.target.value = ''
      return
    }

    setSelectedFile(file)
  }

  /**
   * Print the selected document
   */
  const handlePrint = async () => {
    if (!selectedFile) {
      toast({
        title: 'No File Selected',
        description: 'Please select a file first',
        variant: 'destructive',
      })
      return
    }

    setIsPrinting(true)

    try {
      // Use device's agentId
      const effectiveAgentId = device.agentId || agentId

      // Send print request to UMP using PRINT_FILE action with actual file
      console.log('[Print Document] Sending file to UMP...')
      console.log('[Print Document] Device ID:', device.id)
      console.log('[Print Document] Agent ID:', effectiveAgentId)
      console.log('[Print Document] File:', selectedFile.name)
      console.log('[Print Document] Type:', selectedFile.type)
      console.log('[Print Document] Size:', selectedFile.size, 'bytes')

      // Use printFile method which sends the actual File object via FormData
      const result = await universalIoTService.printFile(
        effectiveAgentId,
        device.id,
        selectedFile
      )

      if (result.success) {
        toast({
          title: 'Print Job Sent',
          description: `Document "${selectedFile.name}" sent to printer successfully`,
        })
        
        // Reset and close
        setSelectedFile(null)
        if (fileInputRef.current) {
          fileInputRef.current.value = ''
        }
        onClose()
      } else {
        throw new Error(result.error || 'Print job failed')
      }
    } catch (error) {
      console.error('[Print Document] Error:', error)
      toast({
        title: 'Print Failed',
        description: error instanceof Error ? error.message : 'Could not send print job',
        variant: 'destructive',
      })
    } finally {
      setIsPrinting(false)
    }
  }

  /**
   * Handle dialog close
   */
  const handleClose = () => {
    if (!isPrinting) {
      setSelectedFile(null)
      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }
      onClose()
    }
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-[600px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-3">
            <Icon icon="solar:printer-bold-duotone" className="h-6 w-6 text-primary" />
            Print Document
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-6 py-4">
          {/* Print Target Section */}
          <div className="space-y-3">
            <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
              <Icon icon="solar:printer-bold" className="h-5 w-5" />
              Print Target:
            </div>
            <div className="flex items-center gap-3 p-4 bg-primary/5 border-2 border-primary/20 rounded-lg">
              <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-primary" />
              <div className="flex-1">
                <div className="font-medium">{device.name}</div>
                <div className="text-sm text-muted-foreground">
                  {device.vendor} {device.model}
                </div>
              </div>
              <div className={`h-2 w-2 rounded-full ${
                device.status === 'online' ? 'bg-green-500' : 'bg-gray-400'
              }`} />
            </div>
          </div>

          {/* File Selection Section */}
          <div className="space-y-3">
            <div className="text-sm font-medium text-muted-foreground">
              Select File (PDF, Word, Excel):
            </div>
            <div className="space-y-2">
              <input
                ref={fileInputRef}
                type="file"
                accept={allowedExtensions}
                onChange={handleFileSelect}
                className="hidden"
                id="document-file-input"
              />
              <label
                htmlFor="document-file-input"
                className="flex items-center gap-3 p-4 border-2 border-dashed border-muted-foreground/25 rounded-lg cursor-pointer hover:border-primary/50 hover:bg-muted/50 transition-colors"
              >
                <div className="px-4 py-2 bg-muted border border-border rounded-md text-sm font-medium">
                  Choose File
                </div>
                <div className="flex-1 text-sm text-muted-foreground">
                  {selectedFile ? selectedFile.name : 'No file chosen'}
                </div>
                {selectedFile && (
                  <div className="text-xs text-muted-foreground">
                    {(selectedFile.size / 1024).toFixed(1)} KB
                  </div>
                )}
              </label>
              <p className="text-xs text-muted-foreground">
                Maximum file size: 10MB
              </p>
            </div>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex justify-end gap-3 pt-4 border-t">
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={isPrinting}
          >
            Cancel
          </Button>
          <Button
            onClick={handlePrint}
            disabled={!selectedFile || isPrinting || device.status !== 'online'}
          >
            {isPrinting ? (
              <>
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                Printing...
              </>
            ) : (
              <>
                <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
                Print
              </>
            )}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export default PrintDocumentDialog
