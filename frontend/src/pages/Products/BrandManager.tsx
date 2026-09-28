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
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

interface Brand {
  id: number
  name: string
  slug: string
  description: string | null
  logo: string | null
  website: string | null
  is_active: boolean
  products_count?: number
}

export function BrandManager() {
  const [brands, setBrands] = useState<Brand[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [editingBrand, setEditingBrand] = useState<Brand | null>(null)
  const { toast } = useToast()

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [website, setWebsite] = useState('')
  const [isActive, setIsActive] = useState(true)

  useEffect(() => {
    fetchBrands()
  }, [])

  const fetchBrands = async () => {
    try {
      const res = await api.get('/brands', { params: { per_page: 100 } })
      const data = res.data.data || res.data
      setBrands(Array.isArray(data) ? data : [])
    } catch (error) {
      console.error('Failed to fetch brands', error)
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      const data: Record<string, unknown> = {
        name,
        is_active: isActive,
      }
      if (description.trim()) data.description = description.trim()
      if (website.trim()) data.website = website.trim()

      if (editingBrand) {
        await api.put(`/brands/${editingBrand.id}`, data)
        toast({ title: 'Brand updated successfully' })
      } else {
        await api.post('/brands', data)
        toast({ title: 'Brand created successfully' })
      }

      setOpen(false)
      resetForm()
      fetchBrands()
    } catch (error: unknown) {
      const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast({ title: message || 'Failed to save brand', variant: 'destructive' })
    }
  }

  const handleEdit = (brand: Brand) => {
    setEditingBrand(brand)
    setName(brand.name)
    setDescription(brand.description || '')
    setWebsite(brand.website || '')
    setIsActive(brand.is_active)
    setOpen(true)
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure? This cannot be undone if the brand has no products.')) return
    try {
      await api.delete(`/brands/${id}`)
      toast({ title: 'Brand deleted successfully' })
      fetchBrands()
    } catch (error: unknown) {
      const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast({ title: message || 'Failed to delete brand', variant: 'destructive' })
    }
  }

  const resetForm = () => {
    setEditingBrand(null)
    setName('')
    setDescription('')
    setWebsite('')
    setIsActive(true)
  }

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon={PRODUCT_SETTINGS_SECTIONS.brands.icon}
        title={PRODUCT_SETTINGS_SECTIONS.brands.title}
        description={PRODUCT_SETTINGS_SECTIONS.brands.description}
        action={
        <Dialog open={open} onOpenChange={(val) => {
          setOpen(val)
          if (!val) resetForm()
        }}>
          <DialogTrigger asChild>
            <HeaderActionButton icon="solar:add-circle-bold-duotone">Add Brand</HeaderActionButton>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{editingBrand ? 'Edit Brand' : 'Add Brand'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">Brand Name</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Apple, Nike, Samsung"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="description">Description</Label>
                <Textarea
                  id="description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Brief description of the brand"
                  rows={3}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="website">Website</Label>
                <Input
                  id="website"
                  type="url"
                  value={website}
                  onChange={(e) => setWebsite(e.target.value)}
                  placeholder="https://example.com"
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="is_active"
                  checked={isActive}
                  onCheckedChange={(checked) => setIsActive(checked as boolean)}
                />
                <Label htmlFor="is_active">Active</Label>
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
              <TableHead>Slug</TableHead>
              <TableHead>Website</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {brands.map((brand) => (
              <TableRow key={brand.id}>
                <TableCell className="font-medium">{brand.name}</TableCell>
                <TableCell className="text-muted-foreground">{brand.slug}</TableCell>
                <TableCell>
                  {brand.website ? (
                    <a
                      href={brand.website}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-blue-600 hover:underline text-sm"
                    >
                      {brand.website}
                    </a>
                  ) : (
                    <span className="text-muted-foreground text-sm">-</span>
                  )}
                </TableCell>
                <TableCell>
                  {brand.is_active ? (
                    <Badge variant="default" className="bg-green-100 text-green-800 hover:bg-green-100">
                      Active
                    </Badge>
                  ) : (
                    <Badge variant="secondary">Inactive</Badge>
                  )}
                </TableCell>
                <TableCell className="w-[1%] whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7"
                      title="Edit brand"
                      aria-label="Edit brand"
                      onClick={() => handleEdit(brand)}
                    >
                      <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 text-destructive hover:text-destructive"
                      title="Delete brand"
                      aria-label="Delete brand"
                      onClick={() => handleDelete(brand.id)}
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
            {brands.length === 0 && !loading && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center">
                  <Icon
                    icon={PRODUCT_SETTINGS_SECTIONS.brands.icon}
                    className="mx-auto h-8 w-8 text-muted-foreground/30"
                  />
                  <p className="mt-2 text-sm text-muted-foreground">No brands found. Add your first brand above.</p>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
