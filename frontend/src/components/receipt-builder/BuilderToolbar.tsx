import { useState } from 'react'
import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Icon } from '@iconify/react'
import { useToast } from '@/hooks/use-toast'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export function BuilderToolbar() {
  const { config, updateConfigName, updatePaperWidth, hasUnsavedChanges, markAsSaved } = useReceiptBuilderStore()
  const { toast } = useToast()
  const [isSaving, setIsSaving] = useState(false)

  const handleSave = async () => {
    if (!config) return

    setIsSaving(true)
    try {
      // TODO: Implement API call to save configuration
      await new Promise(resolve => setTimeout(resolve, 1000)) // Simulate API call
      
      markAsSaved()
      toast({
        title: 'Saved',
        description: 'Receipt template saved successfully',
      })
    } catch (error) {
      toast({
        title: 'Error',
        description: 'Failed to save receipt template',
        variant: 'destructive',
      })
    } finally {
      setIsSaving(false)
    }
  }

  const handlePreview = () => {
    // TODO: Implement preview dialog
    toast({
      title: 'Preview',
      description: 'Preview functionality coming soon',
    })
  }

  const handleExport = () => {
    // TODO: Implement export functionality
    toast({
      title: 'Export',
      description: 'Export functionality coming soon',
    })
  }

  if (!config) return null

  return (
    <div className="border-b bg-background px-6 py-3">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => window.history.back()}>
            <Icon icon="solar:arrow-left-linear" className="w-5 h-5" />
          </Button>
          
          <div className="h-8 w-px bg-border" />
          
          <Input
            value={config.name}
            onChange={(e) => updateConfigName(e.target.value)}
            className="w-64"
            placeholder="Template name"
          />
          
          <Select value={config.paperWidth} onValueChange={(value: any) => updatePaperWidth(value)}>
            <SelectTrigger className="w-32">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="58mm">58mm</SelectItem>
              <SelectItem value="80mm">80mm</SelectItem>
            </SelectContent>
          </Select>
          
          {hasUnsavedChanges && (
            <span className="text-sm text-muted-foreground">• Unsaved changes</span>
          )}
        </div>

        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={handlePreview}>
            <Icon icon="solar:eye-bold-duotone" className="w-4 h-4 mr-2" />
            Preview
          </Button>
          
          <Button variant="outline" onClick={handleExport}>
            <Icon icon="solar:export-bold-duotone" className="w-4 h-4 mr-2" />
            Export
          </Button>
          
          <Button onClick={handleSave} disabled={isSaving || !hasUnsavedChanges}>
            {isSaving ? (
              <>
                <Icon icon="solar:loading-linear" className="w-4 h-4 mr-2 animate-spin" />
                Saving...
              </>
            ) : (
              <>
                <Icon icon="solar:diskette-bold-duotone" className="w-4 h-4 mr-2" />
                Save
              </>
            )}
          </Button>
        </div>
      </div>
    </div>
  )
}

