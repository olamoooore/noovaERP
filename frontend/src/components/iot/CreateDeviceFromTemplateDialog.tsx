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
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface DeviceTemplate {
  id: number
  name: string
  category: string
  type: 'printer' | 'scanner' | 'kiosk' | 'camera' | 'agent'
  vendor: string
  model?: string
  connection_type: 'cloud' | 'lan' | 'agent' | 'bluetooth' | 'usb'
  config: Record<string, any>
  description?: string
  is_verified: boolean
  is_popular: boolean
  supported_features?: string[]
  setup_instructions?: string[]
}

interface CreateDeviceFromTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  template: DeviceTemplate | null
  onSuccess: () => void
}

interface FormData {
  name: string
  config_overrides: Record<string, any>
}

export function CreateDeviceFromTemplateDialog({ 
  open, 
  onOpenChange, 
  template, 
  onSuccess 
}: CreateDeviceFromTemplateDialogProps) {
  const { toast } = useToast()
  const { register, handleSubmit, reset, formState: { errors } } = useForm<FormData>({
    defaultValues: {
      name: '',
      config_overrides: {},
    },
  })

  useEffect(() => {
    if (template) {
      reset({
        name: `${template.vendor} ${template.model || template.name}`,
        config_overrides: {},
      })
    }
  }, [template, reset])

  const createDeviceMutation = useMutation({
    mutationFn: async (data: FormData) => {
      if (!template) throw new Error('No template selected')
      
      const response = await api.post(`/iot/templates/${template.id}/create-device`, {
        name: data.name,
        config_overrides: data.config_overrides,
      })
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Device created',
        description: 'Device has been created from template successfully',
      })
      onSuccess()
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create device',
        variant: 'destructive',
      })
    },
  })

  const onSubmit = (data: FormData) => {
    createDeviceMutation.mutate(data)
  }

  const getDeviceIcon = (type: string, connectionType?: string) => {
    switch (type) {
      case 'printer':
        return connectionType === 'usb' ? 'solar:tag-bold-duotone' : 'solar:printer-bold-duotone'
      case 'scanner':
        return 'solar:qr-code-bold-duotone'
      case 'camera':
        return 'solar:camera-bold-duotone'
      case 'kiosk':
        return 'solar:monitor-bold-duotone'
      default:
        return 'solar:devices-bold-duotone'
    }
  }

  if (!template) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Create Device from Template</DialogTitle>
          <DialogDescription>
            Create a new device using the selected template configuration
          </DialogDescription>
        </DialogHeader>

        {/* Template Preview */}
        <div className="space-y-4">
          <div className="flex items-center gap-3 p-4 bg-muted/50 rounded-lg">
            <div className="p-2 bg-primary/10 rounded-lg">
              <Icon 
                icon={getDeviceIcon(template.type, template.connection_type)} 
                className="h-6 w-6 text-primary" 
              />
            </div>
            <div className="flex-1">
              <h3 className="font-semibold">{template.name}</h3>
              <p className="text-sm text-muted-foreground">
                {template.vendor} {template.model && `• ${template.model}`}
              </p>
              <div className="flex items-center gap-2 mt-1">
                <Badge variant="outline" className="capitalize text-xs">
                  {template.connection_type}
                </Badge>
                {template.is_verified && (
                  <Badge variant="secondary" className="text-xs">
                    <Icon icon="solar:verified-check-bold" className="h-3 w-3 mr-1" />
                    Verified
                  </Badge>
                )}
                {template.is_popular && (
                  <Badge variant="secondary" className="text-xs">
                    <Icon icon="solar:star-bold" className="h-3 w-3 mr-1" />
                    Popular
                  </Badge>
                )}
              </div>
            </div>
          </div>

          {template.description && (
            <p className="text-sm text-muted-foreground">
              {template.description}
            </p>
          )}

          {template.supported_features && template.supported_features.length > 0 && (
            <div className="space-y-2">
              <Label className="text-sm font-medium">Supported Features</Label>
              <div className="flex flex-wrap gap-1">
                {template.supported_features.map((feature) => (
                  <Badge key={feature} variant="secondary" className="text-xs">
                    {feature.replace('_', ' ')}
                  </Badge>
                ))}
              </div>
            </div>
          )}

          <Separator />

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">Device Name</Label>
              <Input
                id="name"
                placeholder="e.g., Counter 1 Label Printer"
                {...register('name', { required: 'Device name is required' })}
              />
              {errors.name && (
                <p className="text-sm text-destructive">{errors.name.message}</p>
              )}
              <p className="text-xs text-muted-foreground">
                Give your device a unique name to identify it in your system
              </p>
            </div>

            {/* Configuration Preview */}
            <div className="space-y-2">
              <Label className="text-sm font-medium">Template Configuration</Label>
              <div className="p-3 bg-muted/30 rounded-lg">
                <pre className="text-xs text-muted-foreground overflow-x-auto">
                  {JSON.stringify(template.config, null, 2)}
                </pre>
              </div>
              <p className="text-xs text-muted-foreground">
                The device will be created with this configuration. You can modify it after creation.
              </p>
            </div>

            {/* Setup Instructions */}
            {template.setup_instructions && template.setup_instructions.length > 0 && (
              <div className="space-y-2">
                <Label className="text-sm font-medium">Setup Instructions</Label>
                <div className="space-y-2 p-3 bg-blue-50 rounded-lg border border-blue-200">
                  <div className="flex items-start gap-2">
                    <Icon icon="solar:info-circle-bold" className="h-4 w-4 text-blue-600 mt-0.5" />
                    <div className="space-y-1">
                      <p className="text-sm font-medium text-blue-900">Next Steps</p>
                      <ol className="text-xs text-blue-700 space-y-1">
                        {template.setup_instructions.map((instruction, index) => (
                          <li key={index} className="flex gap-2">
                            <span className="font-medium">{index + 1}.</span>
                            <span>{instruction}</span>
                          </li>
                        ))}
                      </ol>
                    </div>
                  </div>
                </div>
              </div>
            )}

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={createDeviceMutation.isPending}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={createDeviceMutation.isPending}>
                {createDeviceMutation.isPending && (
                  <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                )}
                Create Device
              </Button>
            </DialogFooter>
          </form>
        </div>
      </DialogContent>
    </Dialog>
  )
}