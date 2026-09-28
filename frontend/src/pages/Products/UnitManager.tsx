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
import { Checkbox } from '@/components/ui/checkbox'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

interface Unit {
  id: number
  name: string
  short_name: string
  allow_decimal: boolean
}

export function UnitManager() {
  const [units, setUnits] = useState<Unit[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editingUnit, setEditingUnit] = useState<Unit | null>(null)
  const { toast } = useToast()

  // Form state
  const [name, setName] = useState('')
  const [shortName, setShortName] = useState('')
  const [allowDecimal, setAllowDecimal] = useState(false)

  useEffect(() => {
    fetchUnits()
  }, [])

  const fetchUnits = async () => {
    try {
      const res = await api.get('/product-units')
      setUnits(res.data)
    } catch (error) {
      console.error('Failed to fetch units', error)
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      const data = {
        name,
        short_name: shortName,
        allow_decimal: allowDecimal,
      }

      if (editingUnit) {
        await api.put(`/product-units/${editingUnit.id}`, data)
        toast({ title: 'Unit updated successfully' })
      } else {
        await api.post('/product-units', data)
        toast({ title: 'Unit created successfully' })
      }

      setOpen(false)
      resetForm()
      fetchUnits()
    } catch (error) {
      toast({ title: 'Failed to save unit', variant: 'destructive' })
    }
  }

  const handleEdit = (unit: Unit) => {
    setEditingUnit(unit)
    setName(unit.name)
    setShortName(unit.short_name)
    setAllowDecimal(unit.allow_decimal)
    setOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure?')) return
    try {
      await api.delete(`/product-units/${id}`)
      toast({ title: 'Unit deleted successfully' })
      fetchUnits()
    } catch (error) {
      toast({ title: 'Failed to delete unit', variant: 'destructive' })
    }
  }

  const resetForm = () => {
    setEditingUnit(null)
    setName('')
    setShortName('')
    setAllowDecimal(false)
  }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon={PRODUCT_SETTINGS_SECTIONS.units.icon}
        title={PRODUCT_SETTINGS_SECTIONS.units.title}
        description={PRODUCT_SETTINGS_SECTIONS.units.description}
        action={
        <Dialog open={open} onOpenChange={(val) => {
            setOpen(val)
            if (!val) resetForm()
        }}>
          <DialogTrigger asChild>
            <HeaderActionButton icon="solar:add-circle-bold-duotone">Add Unit</HeaderActionButton>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{editingUnit ? 'Edit Unit' : 'Add Unit'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">Name</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Kilogram"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="short_name">Short Name</Label>
                <Input
                  id="short_name"
                  value={shortName}
                  onChange={(e) => setShortName(e.target.value)}
                  placeholder="e.g. kg"
                  required
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="allow_decimal"
                  checked={allowDecimal}
                  onCheckedChange={(checked) => setAllowDecimal(checked as boolean)}
                />
                <Label htmlFor="allow_decimal">Allow Decimal</Label>
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
              <TableHead>Short Name</TableHead>
              <TableHead>Allow Decimal</TableHead>
              <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {units.map((unit) => (
              <TableRow key={unit.id}>
                <TableCell>{unit.name}</TableCell>
                <TableCell>{unit.short_name}</TableCell>
                <TableCell>
                  {unit.allow_decimal ? (
                    <Icon icon="solar:check-circle-bold" className="text-green-500" />
                  ) : (
                    <Icon icon="solar:close-circle-bold" className="text-red-500" />
                  )}
                </TableCell>
                <TableCell className="w-[1%] whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      title="Edit unit"
                      aria-label="Edit unit"
                      onClick={() => handleEdit(unit)}
                    >
                      <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 text-destructive hover:text-destructive"
                      title="Delete unit"
                      aria-label="Delete unit"
                      onClick={() => handleDelete(unit.id)}
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
            {units.length === 0 && !loading && (
              <TableRow>
                <TableCell colSpan={4} className="py-10 text-center">
                  <Icon
                    icon={PRODUCT_SETTINGS_SECTIONS.units.icon}
                    className="mx-auto h-8 w-8 text-muted-foreground/30"
                  />
                  <p className="mt-2 text-sm text-muted-foreground">No units found.</p>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
