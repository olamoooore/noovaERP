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
  image_url?: string
  is_verified: boolean
  is_popular: boolean
  supported_features?: string[]
  setup_instructions?: string[]
}

interface IoTTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  template: DeviceTemplate | null
  onSuccess: () => void
}

interface FormData {
  name: string
  category: string
  type: string
  vendor: string
  model: string
  connection_type: string
  description: string
  image_url: string
  is_verified: boolean
  is_popular: boolean
  config_json: string
  supported_features: string
  setup_instructions: string
}

export function IoTTemplateDialog({ open, onOpenChange, template, onSuccess }: IoTTemplateDialogProps) {
  const { toast } = useToast()
  const { register, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm<FormData>({
    defaultValues: {
      name: '',
      category: 'printers',
      type: 'printer',
      vendor: '',
      model: '',
      connection_type: 'lan',
      description: '',
      image_url: '',
      is_verified: false,
      is_popular: false,
      config_json: '{}',
      supported_features: '',
      setup_instructions: '',
    },
  })

  const selectedType = watch('type')
  const selectedCategory = watch('category')

  useEffect(() => {
    if (template) {
      reset({
        name: template.name,
        category: template.category,
        type: template.type,
        vendor: template.vendor,
        model: template.model || '',
        connection_type: template.connection_type,
        description: template.description || '',
        image_url: template.image_url || '',
        is_verified: template.is_verified,
        is_popular: template.is_popular,
        config_json: JSON.stringify(template.config, null, 2),
        supported_features: template.supported_features?.join(', ') || '',
        setup_instructions: template.setup_instructions?.join('\n') || '',
      })
    } else {
      reset({
        name: '',
        category: 'printers',
        type: 'printer',
        vendor: '',
        model: '',
        connection_type: 'lan',
        description: '',
        image_url: '',
        is_verified: false,
        is_popular: false,
        config_json: '{}',
        supported_features: '',
        setup_instructions: '',
      })
    }
  }, [template, reset])

  const saveMutation = useMutation({
    mutationFn: async (data: FormData) => {
      let config = {}
      try {
        config = JSON.parse(data.config_json)
      } catch (e) {
        throw new Error('Invalid JSON in configuration')
      }

      const payload = {
        name: data.name,
        category: data.category,
        type: data.type,
        vendor: data.vendor,
        model: data.model || null,
        connection_type: data.connection_type,
        config,
        description: data.description || null,
        image_url: data.image_url || null,
        is_verified: data.is_verified,
        is_popular: data.is_popular,
        supported_features: data.supported_features 
          ? data.supported_features.split(',').map(f => f.trim()).filter(f => f)
          : null,
        setup_instructions: data.setup_instructions
          ? data.setup_instructions.split('\n').map(s => s.trim()).filter(s => s)
          : null,
      }

      if (template) {
        return await api.put(`/iot/templates/${template.id}`, payload)
      } else {
        return await api.post('/iot/templates', payload)
      }
    },
    onSuccess: () => {
      toast({
        title: template ? 'Template updated' : 'Template created',
        description: template ? 'Template has been updated successfully' : 'Template has been created successfully',
      })
      onSuccess()
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || error.message || 'Failed to save template',
        variant: 'destructive',
      })
    },
  })

  const onSubmit = (data: FormData) => {
    saveMutation.mutate(data)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[600px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{template ? 'Edit Template' : 'Add New Template'}</DialogTitle>
          <DialogDescription>
            {template ? 'Update device template configuration' : 'Create a new device template for easy setup'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="name">Template Name</Label>
              <Input
                id="name"
                placeholder="e.g., GP-D320FX Label Printer"
                {...register('name', { required: 'Name is required' })}
              />
              {errors.name && (
                <p className="text-sm text-destructive">{errors.name.message}</p>
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
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="type">Device Type</Label>
              <Select
                value={selectedType}
                onValueChange={(value) => setValue('type', value)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="printer">Printer</SelectItem>
                  <SelectItem value="scanner">Scanner</SelectItem>
                  <SelectItem value="camera">Camera</SelectItem>
                  <SelectItem value="kiosk">Kiosk</SelectItem>
                  <SelectItem value="agent">Local Agent</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="connection_type">Connection Type</Label>
              <Select
                value={watch('connection_type')}
                onValueChange={(value) => setValue('connection_type', value)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="lan">LAN/Network</SelectItem>
                  <SelectItem value="cloud">Cloud API</SelectItem>
                  <SelectItem value="agent">Local Agent</SelectItem>
                  <SelectItem value="bluetooth">Bluetooth</SelectItem>
                  <SelectItem value="usb">USB Direct</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="vendor">Vendor/Brand</Label>
              <Input
                id="vendor"
                placeholder="e.g., Gprinter, Epson, Zebra"
                {...register('vendor', { required: 'Vendor is required' })}
              />
              {errors.vendor && (
                <p className="text-sm text-destructive">{errors.vendor.message}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="model">Model (Optional)</Label>
              <Input
                id="model"
                placeholder="e.g., GP-D320FX, TM-T88VI"
                {...register('model')}
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              placeholder="Describe the device and its capabilities..."
              rows={3}
              {...register('description')}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="config_json">Configuration (JSON)</Label>
            <Textarea
              id="config_json"
              placeholder='{"key": "value"}'
              rows={4}
              className="font-mono text-sm"
              {...register('config_json', { required: 'Configuration is required' })}
            />
            {errors.config_json && (
              <p className="text-sm text-destructive">{errors.config_json.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="supported_features">Supported Features (comma-separated)</Label>
            <Input
              id="supported_features"
              placeholder="e.g., label_printing, barcode_printing, qr_code_printing"
              {...register('supported_features')}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="setup_instructions">Setup Instructions (one per line)</Label>
            <Textarea
              id="setup_instructions"
              placeholder="Step 1: Connect the device&#10;Step 2: Configure settings&#10;Step 3: Test connection"
              rows={4}
              {...register('setup_instructions')}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
              <Switch
                id="is_verified"
                checked={watch('is_verified')}
                onCheckedChange={(checked) => setValue('is_verified', checked)}
              />
              <Label htmlFor="is_verified">Verified Template</Label>
            </div>

            <div className="flex items-center space-x-2">
              <Switch
                id="is_popular"
                checked={watch('is_popular')}
                onCheckedChange={(checked) => setValue('is_popular', checked)}
              />
              <Label htmlFor="is_popular">Popular Template</Label>
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={saveMutation.isPending}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={saveMutation.isPending}>
              {saveMutation.isPending && (
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
              )}
              {template ? 'Update' : 'Create'} Template
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}