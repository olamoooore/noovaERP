import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { IoTTemplateDialog } from './IoTTemplateDialog'
import { CreateDeviceFromTemplateDialog } from './CreateDeviceFromTemplateDialog'

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
  full_name: string
  category_display_name: string
}

interface Category {
  key: string
  name: string
  count: number
  icon: string
}

export function IoTTemplatesPage() {
  const [searchQuery, setSearchQuery] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<string>('all')
  const [templateDialogOpen, setTemplateDialogOpen] = useState(false)
  const [createDeviceDialogOpen, setCreateDeviceDialogOpen] = useState(false)
  const [selectedTemplate, setSelectedTemplate] = useState<DeviceTemplate | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Fetch categories
  const { data: categories } = useQuery<Category[]>({
    queryKey: ['iot-template-categories'],
    queryFn: async () => {
      const response = await api.get('/iot/templates/categories')
      return response.data
    },
  })

  // Fetch templates
  const { data: templates, isLoading } = useQuery<DeviceTemplate[]>({
    queryKey: ['iot-templates', selectedCategory, searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (selectedCategory !== 'all') {
        params.append('category', selectedCategory)
      }
      if (searchQuery) {
        params.append('search', searchQuery)
      }
      params.append('verified_only', 'false')
      
      const response = await api.get(`/iot/templates?${params}`)
      
      // If we have a category filter, return the array directly
      // If no category filter, we get grouped data, so flatten it
      if (selectedCategory !== 'all') {
        return response.data
      } else {
        // Flatten grouped data
        const grouped = response.data
        const flattened: DeviceTemplate[] = []
        Object.values(grouped).forEach((categoryTemplates: any) => {
          flattened.push(...categoryTemplates)
        })
        return flattened
      }
    },
  })

  const handleCreateDevice = (template: DeviceTemplate) => {
    setSelectedTemplate(template)
    setCreateDeviceDialogOpen(true)
  }

  const handleEditTemplate = (template: DeviceTemplate) => {
    setSelectedTemplate(template)
    setTemplateDialogOpen(true)
  }

  const handleAddNewTemplate = () => {
    setSelectedTemplate(null)
    setTemplateDialogOpen(true)
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

  const filteredTemplates = templates?.filter(template => {
    if (!searchQuery) return true
    const query = searchQuery.toLowerCase()
    return (
      template.name.toLowerCase().includes(query) ||
      template.vendor.toLowerCase().includes(query) ||
      template.model?.toLowerCase().includes(query) ||
      template.description?.toLowerCase().includes(query)
    )
  }) || []

  const popularTemplates = filteredTemplates.filter(t => t.is_popular)
  const verifiedTemplates = filteredTemplates.filter(t => t.is_verified)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="p-3 bg-primary/10 rounded-xl">
            <Icon icon="solar:widget-4-bold-duotone" className="h-8 w-8 text-primary" />
          </div>
          <div>
            <h1 className="text-3xl font-bold">Device Templates</h1>
            <p className="text-muted-foreground">
              Pre-configured device templates for quick setup
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleAddNewTemplate}>
            <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
            Add Template
          </Button>
        </div>
      </div>

      {/* Search and Filters */}
      <div className="flex gap-4">
        <div className="flex-1">
          <Input
            placeholder="Search templates by name, vendor, or model..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="max-w-md"
          />
        </div>
      </div>

      {/* Category Tabs */}
      <Tabs value={selectedCategory} onValueChange={setSelectedCategory} className="space-y-4">
        <TabsList className="grid w-full grid-cols-6">
          <TabsTrigger value="all" className="gap-2">
            <Icon icon="solar:widget-bold" className="h-4 w-4" />
            All
            <Badge variant="secondary" className="ml-1">
              {filteredTemplates.length}
            </Badge>
          </TabsTrigger>
          {categories?.map((category) => (
            <TabsTrigger key={category.key} value={category.key} className="gap-2">
              <Icon icon={category.icon} className="h-4 w-4" />
              {category.name}
              <Badge variant="secondary" className="ml-1">
                {category.count}
              </Badge>
            </TabsTrigger>
          ))}
        </TabsList>

        {/* Popular Templates Section */}
        {selectedCategory === 'all' && popularTemplates.length > 0 && (
          <div className="space-y-4">
            <div className="flex items-center gap-2">
              <Icon icon="solar:star-bold-duotone" className="h-5 w-5 text-yellow-500" />
              <h2 className="text-xl font-semibold">Popular Templates</h2>
            </div>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {popularTemplates.slice(0, 6).map((template) => (
                <TemplateCard
                  key={template.id}
                  template={template}
                  onCreateDevice={handleCreateDevice}
                  onEdit={handleEditTemplate}
                  getDeviceIcon={getDeviceIcon}
                />
              ))}
            </div>
          </div>
        )}

        {/* All Templates */}
        <TabsContent value={selectedCategory} className="space-y-4">
          {filteredTemplates.length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:widget-4-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No templates found</h3>
                <p className="text-muted-foreground text-center mb-4">
                  {searchQuery ? 'Try adjusting your search terms' : 'No templates available in this category'}
                </p>
                {!searchQuery && (
                  <Button onClick={handleAddNewTemplate}>
                    <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                    Add First Template
                  </Button>
                )}
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {filteredTemplates.map((template) => (
                <TemplateCard
                  key={template.id}
                  template={template}
                  onCreateDevice={handleCreateDevice}
                  onEdit={handleEditTemplate}
                  getDeviceIcon={getDeviceIcon}
                />
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* Dialogs */}
      <IoTTemplateDialog
        open={templateDialogOpen}
        onOpenChange={setTemplateDialogOpen}
        template={selectedTemplate}
        onSuccess={() => {
          queryClient.invalidateQueries({ queryKey: ['iot-templates'] })
          queryClient.invalidateQueries({ queryKey: ['iot-template-categories'] })
          setTemplateDialogOpen(false)
        }}
      />

      <CreateDeviceFromTemplateDialog
        open={createDeviceDialogOpen}
        onOpenChange={setCreateDeviceDialogOpen}
        template={selectedTemplate}
        onSuccess={() => {
          queryClient.invalidateQueries({ queryKey: ['iot-devices'] })
          setCreateDeviceDialogOpen(false)
          toast({
            title: 'Device created',
            description: 'Device has been created from template successfully',
          })
        }}
      />
    </div>
  )
}

interface TemplateCardProps {
  template: DeviceTemplate
  onCreateDevice: (template: DeviceTemplate) => void
  onEdit: (template: DeviceTemplate) => void
  getDeviceIcon: (type: string, connectionType?: string) => string
}

function TemplateCard({ template, onCreateDevice, onEdit, getDeviceIcon }: TemplateCardProps) {
  return (
    <Card className="hover:shadow-md transition-shadow">
      <CardHeader>
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-primary/10 rounded-lg">
              <Icon 
                icon={getDeviceIcon(template.type, template.connection_type)} 
                className="h-6 w-6 text-primary" 
              />
            </div>
            <div>
              <CardTitle className="text-lg">{template.name}</CardTitle>
              <CardDescription>
                {template.vendor} {template.model && `• ${template.model}`}
              </CardDescription>
            </div>
          </div>
          <div className="flex items-center gap-1">
            {template.is_popular && (
              <Icon icon="solar:star-bold" className="h-4 w-4 text-yellow-500" />
            )}
            {template.is_verified && (
              <Icon icon="solar:verified-check-bold" className="h-4 w-4 text-green-500" />
            )}
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <div className="space-y-3">
          <p className="text-sm text-muted-foreground line-clamp-2">
            {template.description}
          </p>
          
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">Connection</span>
            <Badge variant="outline" className="capitalize">
              {template.connection_type}
            </Badge>
          </div>

          {template.supported_features && template.supported_features.length > 0 && (
            <div className="flex flex-wrap gap-1">
              {template.supported_features.slice(0, 3).map((feature) => (
                <Badge key={feature} variant="secondary" className="text-xs">
                  {feature.replace('_', ' ')}
                </Badge>
              ))}
              {template.supported_features.length > 3 && (
                <Badge variant="secondary" className="text-xs">
                  +{template.supported_features.length - 3} more
                </Badge>
              )}
            </div>
          )}

          <div className="flex gap-2 pt-2">
            <Button
              className="flex-1"
              onClick={() => onCreateDevice(template)}
            >
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Use Template
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => onEdit(template)}
            >
              <Icon icon="solar:pen-bold" className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}