import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { SettingsSectionHeader } from '@/components/settings/settings-ui'
import { PRODUCT_SETTINGS_SECTIONS } from './productSettingsSections'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Checkbox } from '@/components/ui/checkbox'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

interface CustomField {
  id: number
  name: string
  slug: string
  type: 'text' | 'number' | 'date' | 'boolean'
  is_required: boolean
  default_value: string
}

export function MetaManager() {
  const [fields, setFields] = useState<CustomField[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editingField, setEditingField] = useState<CustomField | null>(null)
  const { toast } = useToast()

  // Form state
  const [name, setName] = useState('')
  const [type, setType] = useState<'text' | 'number' | 'date' | 'boolean'>('text')
  const [isRequired, setIsRequired] = useState(false)
  const [defaultValue, setDefaultValue] = useState('')

  useEffect(() => {
    fetchFields()
  }, [])

  const fetchFields = async () => {
    try {
      const res = await api.get('/product-custom-fields')
      setFields(res.data)
    } catch (error) {
      console.error('Failed to fetch custom fields', error)
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      const data = {
        name,
        type,
        is_required: isRequired,
        default_value: defaultValue,
      }

      if (editingField) {
        await api.put(`/product-custom-fields/${editingField.id}`, data)
        toast({ title: 'Field updated successfully' })
      } else {
        await api.post('/product-custom-fields', data)
        toast({ title: 'Field created successfully' })
      }

      setOpen(false)
      resetForm()
      fetchFields()
    } catch (error) {
      toast({ title: 'Failed to save field', variant: 'destructive' })
    }
  }

  const handleEdit = (field: CustomField) => {
    setEditingField(field)
    setName(field.name)
    setType(field.type)
    setIsRequired(field.is_required)
    setDefaultValue(field.default_value || '')
    setOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure?')) return
    try {
      await api.delete(`/product-custom-fields/${id}`)
      toast({ title: 'Field deleted successfully' })
      fetchFields()
    } catch (error) {
      toast({ title: 'Failed to delete field', variant: 'destructive' })
    }
  }

  const resetForm = () => {
    setEditingField(null)
    setName('')
    setType('text')
    setIsRequired(false)
    setDefaultValue('')
  }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon={PRODUCT_SETTINGS_SECTIONS.meta.icon}
        title={PRODUCT_SETTINGS_SECTIONS.meta.title}
        description={PRODUCT_SETTINGS_SECTIONS.meta.description}
        action={
        <Dialog open={open} onOpenChange={(val) => {
            setOpen(val)
            if (!val) resetForm()
        }}>
          <DialogTrigger asChild>
            <HeaderActionButton icon="solar:add-circle-bold-duotone">Add Field</HeaderActionButton>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{editingField ? 'Edit Field' : 'Add Field'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">Name</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Warranty Period"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="type">Type</Label>
                <Select value={type} onValueChange={(val: any) => setType(val)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="text">Text</SelectItem>
                    <SelectItem value="number">Number</SelectItem>
                    <SelectItem value="date">Date</SelectItem>
                    <SelectItem value="boolean">Boolean (Yes/No)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="default_value">Default Value</Label>
                {type === 'boolean' ? (
                  <div className="flex items-center space-x-2">
                    <Checkbox
                      id="default_value"
                      checked={defaultValue === 'true'}
                      onCheckedChange={(checked) => setDefaultValue(checked === true ? 'true' : 'false')}
                    />
                    <Label htmlFor="default_value">Default: Yes</Label>
                  </div>
                ) : (
                  <Input
                    id="default_value"
                    type={type === 'number' ? 'number' : type === 'date' ? 'date' : 'text'}
                    value={defaultValue}
                    onChange={(e) => setDefaultValue(e.target.value)}
                    placeholder="Optional"
                  />
                )}
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="is_required"
                  checked={isRequired}
                  onCheckedChange={(checked) => setIsRequired(checked as boolean)}
                />
                <Label htmlFor="is_required">Required</Label>
              </div>
              <div className="flex justify-end space-x-2">
                <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit">Save</Button>
              </div>
            </form>
          </DialogContent>
        </Dialog>
              }
      />
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Required</TableHead>
              <TableHead>Default</TableHead>
              <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.map((field) => (
              <TableRow key={field.id}>
                <TableCell>{field.name}</TableCell>
                <TableCell className="capitalize">{field.type}</TableCell>
                <TableCell>
                  {field.is_required ? (
                    <Icon icon="solar:check-circle-bold" className="text-green-500" />
                  ) : (
                    <Icon icon="solar:close-circle-bold" className="text-gray-300" />
                  )}
                </TableCell>
                <TableCell>{field.default_value || '-'}</TableCell>
                <TableCell className="w-[1%] whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      title="Edit field"
                      aria-label="Edit field"
                      onClick={() => handleEdit(field)}
                    >
                      <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 text-destructive hover:text-destructive"
                      title="Delete field"
                      aria-label="Delete field"
                      onClick={() => handleDelete(field.id)}
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
            {fields.length === 0 && !loading && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center">
                  <Icon
                    icon={PRODUCT_SETTINGS_SECTIONS.meta.icon}
                    className="mx-auto h-8 w-8 text-muted-foreground/30"
                  />
                  <p className="mt-2 text-sm text-muted-foreground">No custom fields found.</p>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
