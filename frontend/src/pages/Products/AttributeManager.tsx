import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
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

interface AttributeValue {
  id?: number
  value: string
  color_code?: string
}

interface Attribute {
  id: number
  name: string
  slug: string
  type: 'text' | 'select' | 'color' | 'radio'
  values: AttributeValue[]
}

export function AttributeManager() {
  const [attributes, setAttributes] = useState<Attribute[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editingAttribute, setEditingAttribute] = useState<Attribute | null>(null)
  const { toast } = useToast()

  // Form state
  const [name, setName] = useState('')
  const [type, setType] = useState<'text' | 'select' | 'color' | 'radio'>('select')
  const [values, setValues] = useState<AttributeValue[]>([])

  useEffect(() => {
    fetchAttributes()
  }, [])

  const fetchAttributes = async () => {
    try {
      const res = await api.get('/product-attributes')
      setAttributes(res.data)
    } catch (error) {
      console.error('Failed to fetch attributes', error)
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

      if (editingAttribute) {
        await api.put(`/product-attributes/${editingAttribute.id}`, data)
        toast({ title: 'Attribute updated successfully' })
      } else {
        await api.post('/product-attributes', data)
        toast({ title: 'Attribute created successfully' })
      }

      setOpen(false)
      resetForm()
      fetchAttributes()
    } catch (error) {
      toast({ title: 'Failed to save attribute', variant: 'destructive' })
    }
  }

  const handleEdit = (attr: Attribute) => {
    setEditingAttribute(attr)
    setName(attr.name)
    setType(attr.type)
    setValues(attr.values || [])
    setOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure?')) return
    try {
      await api.delete(`/product-attributes/${id}`)
      toast({ title: 'Attribute deleted successfully' })
      fetchAttributes()
    } catch (error) {
      toast({ title: 'Failed to delete attribute', variant: 'destructive' })
    }
  }

  const resetForm = () => {
    setEditingAttribute(null)
    setName('')
    setType('select')
    setValues([])
  }

  const addValue = () => {
    setValues([...values, { value: '', color_code: '' }])
  }

  const removeValue = (index: number) => {
    const newValues = [...values]
    newValues.splice(index, 1)
    setValues(newValues)
  }

  const updateValue = (index: number, field: keyof AttributeValue, val: string) => {
    const newValues = [...values]
    newValues[index] = { ...newValues[index], [field]: val }
    setValues(newValues)
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle>Attributes</CardTitle>
        <Dialog open={open} onOpenChange={(val) => {
            setOpen(val)
            if (!val) resetForm()
        }}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Add Attribute
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>{editingAttribute ? 'Edit Attribute' : 'Add Attribute'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Name</Label>
                  <Input
                    id="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Color"
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
                      <SelectItem value="radio">Radio</SelectItem>
                      <SelectItem value="color">Color</SelectItem>
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
                        onChange={(e) => updateValue(index, 'value', e.target.value)}
                        required
                      />
                      {type === 'color' && (
                        <Input
                          type="color"
                          className="w-20 p-1 h-10"
                          value={val.color_code || '#000000'}
                          onChange={(e) => updateValue(index, 'color_code', e.target.value)}
                        />
                      )}
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
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Values</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {attributes.map((attr) => (
              <TableRow key={attr.id}>
                <TableCell>{attr.name}</TableCell>
                <TableCell className="capitalize">{attr.type}</TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-1">
                    {attr.values?.map((val) => (
                      <Badge key={val.id} variant="secondary" className="flex items-center gap-1">
                        {attr.type === 'color' && val.color_code && (
                          <span
                            className="w-3 h-3 rounded-full border"
                            style={{ backgroundColor: val.color_code }}
                          />
                        )}
                        {val.value}
                      </Badge>
                    ))}
                  </div>
                </TableCell>
                <TableCell className="text-right space-x-2">
                  <Button variant="ghost" size="sm" onClick={() => handleEdit(attr)}>
                    <Icon icon="solar:pen-bold" className="h-4 w-4" />
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => handleDelete(attr.id)}>
                    <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                  </Button>
                </TableCell>
              </TableRow>
            ))}
            {attributes.length === 0 && !loading && (
              <TableRow>
                <TableCell colSpan={4} className="text-center text-muted-foreground">
                  No attributes found.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
