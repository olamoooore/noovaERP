import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface IoTDevice {
  id: number
  name: string
  type: 'printer' | 'terminal' | 'scanner' | 'kiosk' | 'agent'
  vendor: string
  connection_type: 'cloud' | 'lan' | 'agent' | 'bluetooth' | 'usb'
  config: Record<string, any>
}

interface SaveAsTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  device: IoTDevice | null
  onSuccess: () => void
}

interface FormData {
  template_name: string
  category: string
  description: string
  is_verified: boolean
  is_popular: boolean
}

export function SaveAsTemplateDialog({ open, onOpenChange, device, onSuccess }: SaveAsTemplateDialogProps) {
  const { toast } = useToast()
  const { register, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm<FormData>({
    defaultValues: {
      template_name: '',
      category: 'printers',
      description: '',
      is_verified: false,
      is_popular: false,
    },
  })

  const selectedCategory = watch('category')

  useEffect(() => {
    if (device) {
      // Auto-generate template name and category based on device
      const templateName = `${device.vendor} ${device.config?.model || device.type}`
      const category = device.type === 'printer' ? 'printers' : 
                      device.type === 'scanner' ? 'scanners' :
                      device.type === 'kiosk' ? 'kiosks' : 'printers'
      
      reset({
        template_name: templateName,
        category: category,
        description: `${device.vendor} ${device.type} with ${device.connection_type} connection`,
        is_verified: false,
        is_popular: false,
      })
    }
  }, [device, reset])

  const saveTemplateMutation = useMutation({
    mutationFn: async (data: FormData) => {
      if (!device) throw new Error('No device selected')
      
      const response = await api.post(`/iot/devices/${device.id}/save-as-template`, data)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Template saved',
        description: 'Device configuration has been saved as a template',
      })
      onSuccess()
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to save template',
        variant: 'destructive',
      })
    },
  })

  const onSubmit = (data: FormData) => {
    saveTemplateMutation.mutate(data)
  }

  const getDeviceIcon = (type: string, connectionType?: string) => {
    switch (type) {
      case 'printer':
        return connectionType === 'usb' ? 'solar:tag-bold-duotone' : 'solar:printer-bold-duotone'
      case 'scanner':
        return 'solar:qr-code-bold-duotone'
      case 'kiosk':
        return 'solar:monitor-bold-duotone'
      default:
        return 'solar:devices-bold-duotone'
    }
  }

  if (!device) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Save as Template</DialogTitle>
          <DialogDescription>
            Save this device configuration as a reusable template
          </DialogDescription>
        </DialogHeader>

        {/* Device Preview */}
        <div className="flex items-center gap-3 p-4 bg-muted/50 rounded-lg">
          <div className="p-2 bg-primary/10 rounded-lg">
            <Icon 
              icon={getDeviceIcon(device.type, device.connection_type)} 
              className="h-6 w-6 text-primary" 
            />
          </div>
          <div className="flex-1">
            <h3 className="font-semibold">{device.name}</h3>
            <p className="text-sm text-muted-foreground">
              {device.vendor} • {device.type}
            </p>
            <Badge variant="outline" className="capitalize text-xs mt-1">
              {device.connection_type}
            </Badge>
          </div>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="template_name">Template Name</Label>
            <Input
              id="template_name"
              placeholder="e.g., Gprinter GP-D320FX Label Printer"
              {...register('template_name', { required: 'Template name is required' })}
            />
            {errors.template_name && (
              <p className="text-sm text-destructive">{errors.template_name.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="category">Category</Label>
            <Select
              value={selectedCategory}
              onValueChange={(value) => setValue('category', value)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="printers">Printers</SelectItem>
                <SelectItem value="scanners">Scanners</SelectItem>
                <SelectItem value="cameras">Cameras</SelectItem>
                <SelectItem value="kiosks">Kiosks</SelectItem>
                <SelectItem value="sensors">Sensors</SelectItem>
                <SelectItem value="displays">Displays</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              placeholder="Describe this device template..."
              rows={3}
              {...register('description')}
            />
          </div>

          {/* Configuration Preview */}
          <div className="space-y-2">
            <Label className="text-sm font-medium">Configuration to Save</Label>
            <div className="p-3 bg-muted/30 rounded-lg">
              <pre className="text-xs text-muted-foreground overflow-x-auto">
                {JSON.stringify(device.config, null, 2)}
              </pre>
            </div>
          </div>

          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
              <Switch
                id="is_verified"
                checked={watch('is_verified')}
                onCheckedChange={(checked) => setValue('is_verified', checked)}
              />
              <Label htmlFor="is_verified">Mark as Verified</Label>
            </div>

            <div className="flex items-center space-x-2">
              <Switch
                id="is_popular"
                checked={watch('is_popular')}
                onCheckedChange={(checked) => setValue('is_popular', checked)}
              />
              <Label htmlFor="is_popular">Mark as Popular</Label>
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={saveTemplateMutation.isPending}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={saveTemplateMutation.isPending}>
              {saveTemplateMutation.isPending && (
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
              )}
              Save Template
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}