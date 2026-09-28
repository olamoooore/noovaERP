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
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'
import { Badge } from '@/components/ui/badge'

interface FeatureValue {
  id?: number
  value: string
}

interface Feature {
  id: number
  name: string
  slug: string
  type: 'text' | 'select' | 'toggle'
  values: FeatureValue[]
}

export function FeatureManager() {
  const [features, setFeatures] = useState<Feature[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editingFeature, setEditingFeature] = useState<Feature | null>(null)
  const { toast } = useToast()

  // Form state
  const [name, setName] = useState('')
  const [type, setType] = useState<'text' | 'select' | 'toggle'>('select')
  const [values, setValues] = useState<FeatureValue[]>([])

  useEffect(() => {
    fetchFeatures()
  }, [])

  const fetchFeatures = async () => {
    try {
      const res = await api.get('/product-features')
      setFeatures(res.data)
    } catch (error) {
      console.error('Failed to fetch features', error)
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
        values,
      }

      if (editingFeature) {
        await api.put(`/product-features/${editingFeature.id}`, data)
        toast({ title: 'Feature updated successfully' })
      } else {
        await api.post('/product-features', data)
        toast({ title: 'Feature created successfully' })
      }

      setOpen(false)
      resetForm()
      fetchFeatures()
    } catch (error) {
      toast({ title: 'Failed to save feature', variant: 'destructive' })
    }
  }

  const handleEdit = (feat: Feature) => {
    setEditingFeature(feat)
    setName(feat.name)
    setType(feat.type)
    setValues(feat.values || [])
    setOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure?')) return
    try {
      await api.delete(`/product-features/${id}`)
      toast({ title: 'Feature deleted successfully' })
      fetchFeatures()
    } catch (error) {
      toast({ title: 'Failed to delete feature', variant: 'destructive' })
    }
  }

  const resetForm = () => {
    setEditingFeature(null)
    setName('')
    setType('select')
    setValues([])
  }

  const addValue = () => {
    setValues([...values, { value: '' }])
  }

  const removeValue = (index: number) => {
    const newValues = [...values]
    newValues.splice(index, 1)
    setValues(newValues)
  }

  const updateValue = (index: number, val: string) => {
    const newValues = [...values]
    newValues[index] = { ...newValues[index], value: val }
    setValues(newValues)
  }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon={PRODUCT_SETTINGS_SECTIONS.features.icon}
        title={PRODUCT_SETTINGS_SECTIONS.features.title}
        description={PRODUCT_SETTINGS_SECTIONS.features.description}
        action={
        <Dialog open={open} onOpenChange={(val) => {
            setOpen(val)
            if (!val) resetForm()
        }}>
          <DialogTrigger asChild>
            <HeaderActionButton icon="solar:add-circle-bold-duotone">Add Feature</HeaderActionButton>
          </DialogTrigger>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>{editingFeature ? 'Edit Feature' : 'Add Feature'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Name</Label>
                  <Input
                    id="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Material"
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
                      <SelectItem value="select">Select</SelectItem>
                      <SelectItem value="toggle">Toggle</SelectItem>
                      <SelectItem value="text">Text</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>Values</Label>
                  <Button type="button" variant="outline" size="sm" onClick={addValue}>
                    Add Value
                  </Button>
                </div>
                <div className="space-y-2 max-h-[300px] overflow-y-auto p-1">
                  {values.map((val, index) => (
                    <div key={index} className="flex items-center gap-2">
                      <Input
                        placeholder="Value"
                        value={val.value}
                        onChange={(e) => updateValue(index, e.target.value)}
                        required
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => removeValue(index)}
                      >
                        <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                      </Button>
                    </div>
                  ))}
                </div>
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
              <TableHead>Values</TableHead>
              <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {features.map((feat) => (
              <TableRow key={feat.id}>
                <TableCell>{feat.name}</TableCell>
                <TableCell className="capitalize">{feat.type}</TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-1">
                    {feat.values?.map((val) => (
                      <Badge key={val.id} variant="secondary">
                        {val.value}
                      </Badge>
                    ))}
                  </div>
                </TableCell>
                <TableCell className="w-[1%] whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      title="Edit feature"
                      aria-label="Edit feature"
                      onClick={() => handleEdit(feat)}
                    >
                      <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 text-destructive hover:text-destructive"
                      title="Delete feature"
                      aria-label="Delete feature"
                      onClick={() => handleDelete(feat.id)}
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
            {features.length === 0 && !loading && (
              <TableRow>
                <TableCell colSpan={4} className="py-10 text-center">
                  <Icon
                    icon={PRODUCT_SETTINGS_SECTIONS.features.icon}
                    className="mx-auto h-8 w-8 text-muted-foreground/30"
                  />
                  <p className="mt-2 text-sm text-muted-foreground">No features found.</p>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
