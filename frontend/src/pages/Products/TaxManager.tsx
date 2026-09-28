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

interface Tax {
  id: number
  name: string
  rate: number
  is_default: boolean
}

export function TaxManager() {
  const [taxes, setTaxes] = useState<Tax[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editingTax, setEditingTax] = useState<Tax | null>(null)
  const { toast } = useToast()

  // Form state
  const [name, setName] = useState('')
  const [rate, setRate] = useState('')
  const [isDefault, setIsDefault] = useState(false)

  useEffect(() => {
    fetchTaxes()
  }, [])

  const fetchTaxes = async () => {
    try {
      const res = await api.get('/taxes')
      setTaxes(res.data)
    } catch (error) {
      console.error('Failed to fetch taxes', error)
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      const data = {
        name,
        rate: parseFloat(rate),
        is_default: isDefault,
      }

      if (editingTax) {
        await api.put(`/taxes/${editingTax.id}`, data)
        toast({ title: 'Tax updated successfully' })
      } else {
        await api.post('/taxes', data)
        toast({ title: 'Tax created successfully' })
      }

      setOpen(false)
      resetForm()
      fetchTaxes()
    } catch (error) {
      toast({ title: 'Failed to save tax', variant: 'destructive' })
    }
  }

  const handleEdit = (tax: Tax) => {
    setEditingTax(tax)
    setName(tax.name)
    setRate(tax.rate.toString())
    setIsDefault(tax.is_default)
    setOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure?')) return
    try {
      await api.delete(`/taxes/${id}`)
      toast({ title: 'Tax deleted successfully' })
      fetchTaxes()
    } catch (error) {
      toast({ title: 'Failed to delete tax', variant: 'destructive' })
    }
  }

  const resetForm = () => {
    setEditingTax(null)
    setName('')
    setRate('')
    setIsDefault(false)
  }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon={PRODUCT_SETTINGS_SECTIONS.taxes.icon}
        title={PRODUCT_SETTINGS_SECTIONS.taxes.title}
        description={PRODUCT_SETTINGS_SECTIONS.taxes.description}
        action={
        <Dialog open={open} onOpenChange={(val) => {
            setOpen(val)
            if (!val) resetForm()
        }}>
          <DialogTrigger asChild>
            <HeaderActionButton icon="solar:add-circle-bold-duotone">Add Tax</HeaderActionButton>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{editingTax ? 'Edit Tax' : 'Add Tax'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">Name</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. VAT"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="rate">Rate (%)</Label>
                <Input
                  id="rate"
                  type="number"
                  step="0.01"
                  value={rate}
                  onChange={(e) => setRate(e.target.value)}
                  placeholder="e.g. 20"
                  required
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="is_default"
                  checked={isDefault}
                  onCheckedChange={(checked) => setIsDefault(checked as boolean)}
                />
                <Label htmlFor="is_default">Default Tax</Label>
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
              <TableHead>Rate</TableHead>
              <TableHead>Default</TableHead>
              <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {taxes.map((tax) => (
              <TableRow key={tax.id}>
                <TableCell>{tax.name}</TableCell>
                <TableCell>{tax.rate}%</TableCell>
                <TableCell>
                  {tax.is_default ? (
                    <Icon icon="solar:check-circle-bold" className="text-green-500" />
                  ) : (
                    <Icon icon="solar:close-circle-bold" className="text-gray-300" />
                  )}
                </TableCell>
                <TableCell className="w-[1%] whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      title="Edit tax"
                      aria-label="Edit tax"
                      onClick={() => handleEdit(tax)}
                    >
                      <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 text-destructive hover:text-destructive"
                      title="Delete tax"
                      aria-label="Delete tax"
                      onClick={() => handleDelete(tax.id)}
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
            {taxes.length === 0 && !loading && (
              <TableRow>
                <TableCell colSpan={4} className="py-10 text-center">
                  <Icon
                    icon={PRODUCT_SETTINGS_SECTIONS.taxes.icon}
                    className="mx-auto h-8 w-8 text-muted-foreground/30"
                  />
                  <p className="mt-2 text-sm text-muted-foreground">No taxes found.</p>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
