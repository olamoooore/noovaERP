import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { Check, ChevronsUpDown } from 'lucide-react'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { useSettingsStore } from '@/store/settingsStore'
import { formatCurrency } from '@/lib/utils'
import { AnimatedDropzone } from '@/components/react-bits/AnimatedDropzone'

type TaxMode = 'default' | 'none' | 'custom' | 'tax'

type ProductType = 'product' | 'service'

interface Category {
  id: number
  name: string
  parent_id?: number | null
}

interface Tax {
  id: number
  name: string
  rate: number
  is_active: boolean
}

interface ProductUnit {
  id: number
  name: string
  short_name?: string | null
  allow_decimal: boolean
  is_active: boolean
}

interface ProductAttributeValue {
  id: number
  value: string
  color_code?: string | null
}

interface ProductAttribute {
  id: number
  name: string
  type: string
  is_active: boolean
  values?: ProductAttributeValue[]
}

interface ProductFeatureValueOption {
  id: number
  value: string
}

interface ProductFeature {
  id: number
  name: string
  type: string
  is_active: boolean
  values?: ProductFeatureValueOption[]
}

interface ProductCustomField {
  id: number
  name: string
  type: string
  is_active: boolean
  is_required?: boolean
}

export function ProductEditPage() {
  const navigate = useNavigate()
  const { id } = useParams()
  const productId = Number(id)
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const taxConfig = useSettingsStore((s) => s.tax)

  const [formData, setFormData] = useState({
    name: '',
    secondary_name: '',
    sku: '',
    barcode: '',
    type: 'product' as ProductType,
    category_parent_id: '',
    subcategory_id: '',
    price: '',
    cost: '',
    track_inventory: true,
    is_preorder: false,
    is_new_arrival: false,
    preorder_available_at: '',
    stock_quantity: '',
    description: '',

    unit: '',

    image: '' as string,
    gallery: [] as string[],

    tax_mode: 'default' as TaxMode,
    tax_included: false,
    tax_rate_override: '',
    tax_id: '',
  })

  const [singleAttributes, setSingleAttributes] = useState<{ attributeId: number | string; values: string[] }[]>([])
  const [singleAttrPopoverOpen, setSingleAttrPopoverOpen] = useState<Record<number, boolean>>({})
  const [singleAttrSearch, setSingleAttrSearch] = useState<Record<number, string>>({})

  const [unitPopoverOpen, setUnitPopoverOpen] = useState(false)
  const [unitSearch, setUnitSearch] = useState('')

  const [featureValues, setFeatureValues] = useState<Record<number, string>>({})
  const [customFieldValues, setCustomFieldValues] = useState<Record<number, string>>({})

  const { data: product } = useQuery({
    queryKey: ['product', productId],
    enabled: Number.isFinite(productId) && productId > 0,
    queryFn: async () => {
      const res = await api.get(`/products/${productId}`)
      return res.data
    },
  })

  const { data: categories } = useQuery<Category[]>({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: taxes } = useQuery<Tax[]>({
    queryKey: ['taxes'],
    queryFn: async () => {
      const response = await api.get('/taxes')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: units } = useQuery<ProductUnit[]>({
    queryKey: ['product-units'],
    queryFn: async () => {
      const response = await api.get('/product-units')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: attributes } = useQuery<ProductAttribute[]>({
    queryKey: ['product-attributes'],
    queryFn: async () => {
      const response = await api.get('/product-attributes')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: features } = useQuery<ProductFeature[]>({
    queryKey: ['product-features'],
    queryFn: async () => {
      const response = await api.get('/product-features')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: customFields } = useQuery<ProductCustomField[]>({
    queryKey: ['product-custom-fields'],
    queryFn: async () => {
      const response = await api.get('/product-custom-fields')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const categoriesById = useMemo(() => {
    const map = new Map<number, Category>()
    for (const c of categories || []) map.set(c.id, c)
    return map
  }, [categories])

  const categoryPath = (cat: Category): string => {
    const parts: string[] = [cat.name]
    let current: Category | undefined = cat
    let safety = 0
    while (current?.parent_id && safety < 10) {
      const parent = categoriesById.get(current.parent_id)
      if (!parent) break
      parts.unshift(parent.name)
      current = parent
      safety += 1
    }
    return parts.join(' / ')
  }

  const categoryOptions = useMemo(() => {
    const list = (categories || []).slice()
    list.sort((a, b) => categoryPath(a).localeCompare(categoryPath(b)))
    return list
  }, [categories])

  const activeUnits = useMemo(() => (units || []).filter((u) => u.is_active), [units])
  const activeFeatures = useMemo(() => (features || []).filter((f) => f.is_active), [features])
  const activeCustomFields = useMemo(
    () => (customFields || []).filter((f) => f.is_active),
    [customFields]
  )

  useEffect(() => {
    if (!product) return

    const categoryId = product.category_id ? String(product.category_id) : ''

    setFormData((prev) => ({
      ...prev,
      name: product.name || '',
      secondary_name: product.secondary_name || '',
      sku: product.sku || '',
      barcode: product.barcode || '',
      type: (product.type || 'product') as ProductType,
      category_parent_id: categoryId,
      subcategory_id: '',
      cost: product.cost != null ? String(product.cost) : '',
      price: product.price != null ? String(product.price) : '',
      track_inventory: !!product.track_inventory,
      is_preorder: !!product.is_preorder,
      is_new_arrival: !!product.is_new_arrival,
      preorder_available_at: product.preorder_available_at ? String(product.preorder_available_at).slice(0, 10) : '',
      stock_quantity: product.stock_quantity != null ? String(product.stock_quantity) : '',
      description: product.description || '',
      image: product.image || '',
      gallery: Array.isArray(product.gallery) ? product.gallery : [],
      unit: product.unit || '',
      tax_mode: (product.tax_mode || 'default') as TaxMode,
      tax_included: !!product.tax_included,
      tax_rate_override: product.tax_rate_override != null ? String(product.tax_rate_override) : '',
      tax_id: product.tax_id != null ? String(product.tax_id) : '',
    }))

    // hydrate single-product attributes (stored as JSON array)
    if (Array.isArray(product.attributes)) {
      const next = product.attributes
        .map((row: any) => {
          const attributeId = typeof row.attribute_id === 'number' ? row.attribute_id : (row.attribute_name || '')
          const values = Array.isArray(row.values) ? row.values.map((v: any) => String(v)) : []
          return { attributeId, values }
        })
        .filter((r: any) => r.attributeId && Array.isArray(r.values))
      setSingleAttributes(next)
    }

    // hydrate feature/custom values if the API includes them
    if (Array.isArray(product.feature_values)) {
      const map: Record<number, string> = {}
      for (const row of product.feature_values) {
        if (row?.product_feature_id) map[Number(row.product_feature_id)] = row.value == null ? '' : String(row.value)
      }
      setFeatureValues(map)
    }
    if (Array.isArray(product.custom_field_values)) {
      const map: Record<number, string> = {}
      for (const row of product.custom_field_values) {
        if (row?.product_custom_field_id) map[Number(row.product_custom_field_id)] = row.value == null ? '' : String(row.value)
      }
      setCustomFieldValues(map)
    }
  }, [product, categories])

  const uploadMutation = useMutation({
    mutationFn: async ({ file, folder }: { file: File; folder: string }) => {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('folder', folder)
      const response = await api.post('/uploads', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as { url: string; path: string }
    },
  })

  const updateMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.put(`/products/${productId}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      queryClient.invalidateQueries({ queryKey: ['product', productId] })
      toast({ title: 'Product updated successfully' })
      navigate(`/products/${productId}`)
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to update product' })
    },
  })

  const handlePickMainImage = async (file: File | null) => {
    if (!file) return
    try {
      const res = await uploadMutation.mutateAsync({ file, folder: 'products' })
      setFormData((prev) => ({ ...prev, image: res.url }))
      toast({ title: 'Main image uploaded' })
    } catch {
      toast({ variant: 'destructive', title: 'Failed to upload image' })
    }
  }

  const handlePickGalleryFiles = async (files: File[]) => {
    if (!files || files.length === 0) return
    const nextUrls: string[] = []

    for (const file of files) {
      try {
        const res = await uploadMutation.mutateAsync({ file, folder: 'products/gallery' })
        nextUrls.push(res.url)
      } catch {
        toast({ variant: 'destructive', title: 'Failed to upload a gallery image' })
        break
      }
    }

    if (nextUrls.length > 0) {
      setFormData((prev) => ({ ...prev, gallery: [...prev.gallery, ...nextUrls] }))
      toast({ title: `Uploaded ${nextUrls.length} gallery image(s)` })
    }
  }

  const selectedTaxRate = useMemo(() => {
    if (formData.tax_mode === 'none') return 0
    if (formData.tax_mode === 'custom') {
      const r = parseFloat(formData.tax_rate_override || '0')
      return Number.isFinite(r) ? r : 0
    }
    if (formData.tax_mode === 'tax' && formData.tax_id) {
      const match = taxes?.find((t) => t.id === Number(formData.tax_id))
      return match?.rate ?? 0
    }

    if (typeof taxConfig.default_tax_rate === 'number') return taxConfig.default_tax_rate
    if (taxConfig.default_tax_id) {
      const match = taxes?.find((t) => t.id === taxConfig.default_tax_id)
      return match?.rate ?? 0
    }
    return 0
  }, [formData.tax_mode, formData.tax_rate_override, formData.tax_id, taxes, taxConfig.default_tax_rate, taxConfig.default_tax_id])

  const pricingPreview = useMemo(() => {
    const price = parseFloat(formData.price || '0')
    const rate = selectedTaxRate
    const r = rate > 0 ? rate / 100 : 0

    if (!Number.isFinite(price) || price <= 0 || r <= 0) {
      return {
        net: price > 0 ? price : 0,
        tax: 0,
        gross: price > 0 ? price : 0,
      }
    }

    if (formData.tax_included) {
      const net = price / (1 + r)
      const tax = price - net
      return { net, tax, gross: price }
    }

    const tax = price * r
    return { net: price, tax, gross: price + tax }
  }, [formData.price, formData.tax_included, selectedTaxRate])

  const taxSelectValue = useMemo(() => {
    if (formData.tax_mode === 'tax' && formData.tax_id) return `tax:${formData.tax_id}`
    return formData.tax_mode
  }, [formData.tax_mode, formData.tax_id])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const finalCategoryId = (formData.category_parent_id || '').trim()

    const payloadFeatureValues = Object.entries(featureValues)
      .map(([featureId, value]) => ({
        product_feature_id: Number(featureId),
        value,
      }))
      .filter((x) => Number.isFinite(x.product_feature_id) && x.value !== '')

    const payloadCustomFieldValues = Object.entries(customFieldValues)
      .map(([fieldId, value]) => ({
        product_custom_field_id: Number(fieldId),
        value,
      }))
      .filter((x) => Number.isFinite(x.product_custom_field_id) && x.value !== '')

    const payloadAttributes = singleAttributes
      .map((a) => {
        const values = (a.values || []).map((v) => v.trim()).filter(Boolean)
        if (values.length === 0) return null

        if (typeof a.attributeId === 'number') {
          if (!Number.isFinite(a.attributeId) || a.attributeId <= 0) return null
          return { attribute_id: a.attributeId, values }
        }

        const name = (a.attributeId || '').toString().trim()
        if (!name) return null
        return { attribute_name: name, values }
      })
      .filter(Boolean)

    updateMutation.mutate({
      name: formData.name,
      secondary_name: formData.secondary_name || null,
      sku: formData.sku,
      barcode: formData.barcode || null,
      type: formData.type,
      category_id: finalCategoryId ? parseInt(finalCategoryId) : null,
      cost: parseFloat(formData.cost),
      price: parseFloat(formData.price),
      track_inventory: !!formData.track_inventory,
      is_preorder: !!formData.is_preorder,
      is_new_arrival: !!formData.is_new_arrival,
      preorder_available_at: formData.preorder_available_at ? formData.preorder_available_at : null,
      stock_quantity: formData.track_inventory ? parseInt(formData.stock_quantity) : null,
      description: formData.description || null,
      image: formData.image || null,
      gallery: formData.gallery || [],

      unit: formData.unit || null,

      tax_mode: formData.tax_mode,
      tax_included: !!formData.tax_included,
      tax_rate_override:
        formData.tax_mode === 'custom' && formData.tax_rate_override !== ''
          ? parseFloat(formData.tax_rate_override)
          : null,
      tax_id: formData.tax_mode === 'tax' && formData.tax_id ? parseInt(formData.tax_id) : null,
      is_active: true,

      feature_values: payloadFeatureValues,
      custom_field_values: payloadCustomFieldValues,
      attributes: payloadAttributes,
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Link to="/products" className="hover:underline">Products</Link>
            <span>/</span>
            <span className="text-foreground">Edit</span>
          </div>
          <h1 className="text-3xl font-bold mt-1">Edit Product</h1>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" asChild>
            <Link to={`/products/${productId}`}>
              <Icon icon="solar:alt-arrow-left-bold" className="mr-2 h-4 w-4" />
              Back
            </Link>
          </Button>
          <Button type="submit" form="product-form" disabled={updateMutation.isPending}>
            <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
            Save
          </Button>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Basic info</CardTitle>
            </CardHeader>
            <CardContent>
              <form id="product-form" onSubmit={handleSubmit} className="space-y-5">
                <div className="space-y-2">
                  <Label htmlFor="name">Product Name</Label>
                  <Input
                    id="name"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    required
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="secondary_name">Secondary Name (optional)</Label>
                  <Input
                    id="secondary_name"
                    value={formData.secondary_name}
                    onChange={(e) => setFormData({ ...formData, secondary_name: e.target.value })}
                    placeholder="e.g. 500ml / Small / Variant label"
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="sku">SKU</Label>
                    <Input
                      id="sku"
                      value={formData.sku}
                      onChange={(e) => setFormData({ ...formData, sku: e.target.value })}
                      required
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="barcode">Barcode (optional)</Label>
                    <Input
                      id="barcode"
                      value={formData.barcode}
                      onChange={(e) => setFormData({ ...formData, barcode: e.target.value })}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Type</Label>
                    <Select
                      value={formData.type}
                      onValueChange={(v) => setFormData({ ...formData, type: v as ProductType })}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="product">Product</SelectItem>
                        <SelectItem value="service">Service</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2 flex flex-col">
                    <Label>Category</Label>
                    <Popover>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          role="combobox"
                          className={cn(
                            'w-full justify-between',
                            !formData.category_parent_id && 'text-muted-foreground'
                          )}
                        >
                          {formData.category_parent_id
                            ? categoryOptions.find((cat) => String(cat.id) === formData.category_parent_id)?.name ||
                              categoryPath(categoryOptions.find((cat) => String(cat.id) === formData.category_parent_id)!)
                            : 'Select category'}
                          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent className="w-[400px] p-0">
                        <Command>
                          <CommandInput placeholder="Search category..." />
                          <CommandList>
                            <CommandEmpty>No category found.</CommandEmpty>
                            <CommandGroup>
                              <CommandItem
                                value="none"
                                onSelect={() => setFormData({ ...formData, category_parent_id: '' })}
                              >
                                <Check
                                  className={cn(
                                    'mr-2 h-4 w-4',
                                    formData.category_parent_id === '' ? 'opacity-100' : 'opacity-0'
                                  )}
                                />
                                None
                              </CommandItem>
                              {categoryOptions.map((cat) => (
                                <CommandItem
                                  key={cat.id}
                                  value={categoryPath(cat)}
                                  onSelect={() => setFormData({ ...formData, category_parent_id: String(cat.id) })}
                                >
                                  <Check
                                    className={cn(
                                      'mr-2 h-4 w-4',
                                      formData.category_parent_id === String(cat.id) ? 'opacity-100' : 'opacity-0'
                                    )}
                                  />
                                  {categoryPath(cat)}
                                </CommandItem>
                              ))}
                            </CommandGroup>
                          </CommandList>
                        </Command>
                      </PopoverContent>
                    </Popover>
                    <div className="text-xs text-muted-foreground">Shows full hierarchy (e.g. Men / Shoes / Lifestyle Shoes)</div>
                  </div>
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Unit</Label>
                    <Popover open={unitPopoverOpen} onOpenChange={setUnitPopoverOpen}>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          role="combobox"
                          className={cn('w-full justify-between', !formData.unit && 'text-muted-foreground')}
                        >
                          {(() => {
                            const current = (activeUnits.length ? activeUnits : []).find(
                              (u) => String(u.short_name || u.name) === (formData.unit || '')
                            )
                            if (current) {
                              return current.short_name ? `${current.name} (${current.short_name})` : current.name
                            }
                            return formData.unit ? formData.unit : 'Select unit'
                          })()}
                          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent className="w-[320px] p-0">
                        <Command>
                          <CommandInput
                            placeholder="Search unit..."
                            value={unitSearch}
                            onValueChange={setUnitSearch}
                            onKeyDown={(e) => {
                              if (e.key !== 'Enter') return
                              e.preventDefault()
                              const name = unitSearch.trim()
                              if (!name) return
                              setFormData((prev) => ({ ...prev, unit: name }))
                              setUnitPopoverOpen(false)
                            }}
                          />
                          <CommandList>
                            <CommandEmpty>No unit found.</CommandEmpty>
                            {unitSearch.trim() ? (
                              <CommandGroup heading="Create">
                                <CommandItem
                                  value={`create:${unitSearch.trim()}`}
                                  onSelect={() => {
                                    const name = unitSearch.trim()
                                    if (!name) return
                                    setFormData((prev) => ({ ...prev, unit: name }))
                                    setUnitPopoverOpen(false)
                                  }}
                                >
                                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                                  Use "{unitSearch.trim()}"
                                </CommandItem>
                              </CommandGroup>
                            ) : null}

                            <CommandGroup heading="Units">
                              {(activeUnits.length
                                ? activeUnits
                                : [{ id: 0, name: 'pcs', short_name: 'pcs', allow_decimal: false, is_active: true }]
                              ).map((u) => {
                                const value = String(u.short_name || u.name)
                                const label = u.short_name ? `${u.name} (${u.short_name})` : u.name
                                return (
                                  <CommandItem
                                    key={u.id ? `u-${u.id}` : 'pcs'}
                                    value={label}
                                    onSelect={() => {
                                      setFormData((prev) => ({ ...prev, unit: value }))
                                      setUnitPopoverOpen(false)
                                    }}
                                  >
                                    <Check
                                      className={cn(
                                        'mr-2 h-4 w-4',
                                        (formData.unit || '') === value ? 'opacity-100' : 'opacity-0'
                                      )}
                                    />
                                    {label}
                                  </CommandItem>
                                )
                              })}
                            </CommandGroup>
                          </CommandList>
                        </Command>
                      </PopoverContent>
                    </Popover>
                    <div className="text-xs text-muted-foreground">Pulled from Product Settings → Units</div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="description">Description</Label>
                    <Input
                      id="description"
                      value={formData.description}
                      onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                      placeholder="Short description"
                    />
                  </div>
                </div>

                <Separator />

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="cost">Cost</Label>
                    <Input
                      id="cost"
                      type="number"
                      step="0.01"
                      value={formData.cost}
                      onChange={(e) => setFormData({ ...formData, cost: e.target.value })}
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="price">Price</Label>
                    <Input
                      id="price"
                      type="number"
                      step="0.01"
                      value={formData.price}
                      onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                      required
                    />
                  </div>
                </div>

                <Separator />

                <div className="flex items-center gap-2">
                  <Checkbox
                    checked={formData.track_inventory}
                    onCheckedChange={(checked) =>
                      setFormData({ ...formData, track_inventory: checked === true })
                    }
                  />
                  <Label
                    className="cursor-pointer"
                    onClick={() => setFormData({ ...formData, track_inventory: !formData.track_inventory })}
                  >
                    Track inventory
                  </Label>
                </div>

                <div className="flex items-start gap-2">
                  <Checkbox
                    checked={formData.is_preorder}
                    onCheckedChange={(checked) =>
                      setFormData({ ...formData, is_preorder: checked === true })
                    }
                  />
                  <div className="space-y-2">
                    <Label
                      className="cursor-pointer"
                      onClick={() => setFormData({ ...formData, is_preorder: !formData.is_preorder })}
                    >
                      Enable pre-order
                    </Label>
                    <div className="text-xs text-muted-foreground">
                      Allows customers to buy even when stock is low/out.
                    </div>
                    {formData.is_preorder ? (
                      <div className="space-y-2">
                        <Label className="text-xs text-muted-foreground">Expected availability date (optional)</Label>
                        <Input
                          type="date"
                          value={formData.preorder_available_at}
                          onChange={(e) =>
                            setFormData({ ...formData, preorder_available_at: e.target.value })
                          }
                        />
                      </div>
                    ) : null}
                  </div>
                </div>

                <div className="flex items-center gap-2 h-fit">
                  <Checkbox
                    checked={formData.is_new_arrival}
                    onCheckedChange={(checked) =>
                      setFormData({ ...formData, is_new_arrival: checked === true })
                    }
                  />
                  <Label
                    className="cursor-pointer"
                    onClick={() => setFormData({ ...formData, is_new_arrival: !formData.is_new_arrival })}
                  >
                    Mark as New Arrival
                  </Label>
                </div>

                {formData.track_inventory && (
                  <div className="space-y-2">
                    <Label htmlFor="stock">Stock Quantity</Label>
                    <Input
                      id="stock"
                      type="number"
                      value={formData.stock_quantity}
                      onChange={(e) => setFormData({ ...formData, stock_quantity: e.target.value })}
                      required
                    />
                  </div>
                )}

                <Separator />

                <Tabs defaultValue="tax" className="w-full">
                  <TabsList className="grid w-full grid-cols-4">
                    <TabsTrigger value="tax">Tax</TabsTrigger>
                    <TabsTrigger value="attributes">Attributes</TabsTrigger>
                    <TabsTrigger value="features">Features</TabsTrigger>
                    <TabsTrigger value="custom">Custom Fields</TabsTrigger>
                  </TabsList>

                  <TabsContent value="tax" className="pt-4">
                    <div className="rounded-lg border p-3 space-y-3">
                      <div className="font-medium">Tax</div>

                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                      <Label>Tax</Label>
                      <Select
                        value={taxSelectValue}
                        onValueChange={(v) => {
                          if (v.startsWith('tax:')) {
                            const tid = v.split(':')[1] || ''
                            setFormData({
                              ...formData,
                              tax_mode: 'tax',
                              tax_id: tid,
                              tax_rate_override: '',
                            })
                            return
                          }

                          const mode = v as TaxMode
                          setFormData({
                            ...formData,
                            tax_mode: mode,
                            tax_id: '',
                            tax_rate_override: mode === 'custom' ? formData.tax_rate_override : '',
                          })
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="default">
                            Default tax{taxConfig.default_tax_name ? ` (${taxConfig.default_tax_name})` : ''}
                            {typeof taxConfig.default_tax_rate === 'number' ? ` — ${taxConfig.default_tax_rate}%` : ''}
                          </SelectItem>
                          <SelectItem value="none">No tax</SelectItem>
                          <SelectItem value="custom">Custom tax rate</SelectItem>
                          {(taxes || [])
                            .filter((t) => t.is_active)
                            .map((t) => (
                              <SelectItem key={t.id} value={`tax:${t.id}`}>
                                {t.name} ({t.rate}%)
                              </SelectItem>
                            ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <Label>Tax Method</Label>
                      <Select
                        value={formData.tax_included ? 'inclusive' : 'exclusive'}
                        onValueChange={(v) => setFormData({ ...formData, tax_included: v === 'inclusive' })}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="exclusive">Tax exclusive (tax added on top)</SelectItem>
                          <SelectItem value="inclusive">Tax inclusive (price includes tax)</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </div>

                  {formData.tax_mode === 'custom' && (
                    <div className="space-y-2">
                      <Label htmlFor="tax_rate_override">Custom Tax Rate (%)</Label>
                      <Input
                        id="tax_rate_override"
                        type="number"
                        step="0.01"
                        value={formData.tax_rate_override}
                        onChange={(e) => setFormData({ ...formData, tax_rate_override: e.target.value })}
                        placeholder="e.g. 9"
                      />
                    </div>
                  )}

                  <div className="rounded-md border p-3 text-sm">
                    <div className="flex items-center justify-between">
                      <div className="text-muted-foreground">Effective tax rate</div>
                      <div className="font-medium">{selectedTaxRate}%</div>
                    </div>
                    <div className="mt-2 grid grid-cols-3 gap-2">
                      <div>
                        <div className="text-xs text-muted-foreground">Net</div>
                        <div className="font-medium">{formatCurrency(pricingPreview.net)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Tax</div>
                        <div className="font-medium">{formatCurrency(pricingPreview.tax)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Gross</div>
                        <div className="font-medium">{formatCurrency(pricingPreview.gross)}</div>
                      </div>
                    </div>
                  </div>
                </div>
                  </TabsContent>

                  <TabsContent value="attributes" className="pt-4">
                    <div className="rounded-lg border p-3 space-y-3">
                      <div className="text-sm text-muted-foreground">Select attributes and values for this product.</div>

                      {singleAttributes.length ? (
                        <div className="space-y-4">
                          {singleAttributes.map((sel, index) => {
                            const isExisting = typeof sel.attributeId === 'number'
                            const selectedAttr = isExisting ? attributes?.find((a) => a.id === sel.attributeId) : null
                            const isColor =
                              selectedAttr?.name.toLowerCase() === 'color' ||
                              (typeof sel.attributeId === 'string' && sel.attributeId.toLowerCase() === 'color')

                            const searchTerm = singleAttrSearch[index] ?? ''
                            const hasExactMatch = (attributes ?? []).some(
                              (a) => a.name.toLowerCase() === searchTerm.trim().toLowerCase()
                            )

                            return (
                              <div
                                key={index}
                                className="grid grid-cols-1 md:grid-cols-3 gap-4 items-start border p-3 rounded-md bg-background"
                              >
                                <div className="space-y-2">
                                  <Label>Attribute</Label>
                                  <div className="flex gap-2">
                                    <Popover
                                      open={!!singleAttrPopoverOpen[index]}
                                      onOpenChange={(open) =>
                                        setSingleAttrPopoverOpen((prev) => ({ ...prev, [index]: open }))
                                      }
                                    >
                                      <PopoverTrigger asChild>
                                        <Button type="button" variant="outline" role="combobox" className="w-full justify-between">
                                          {isExisting
                                            ? selectedAttr?.name
                                            : sel.attributeId
                                              ? sel.attributeId
                                              : 'Select Attribute'}
                                          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                      </PopoverTrigger>
                                      <PopoverContent className="w-[260px] p-0">
                                        <Command>
                                          <CommandInput
                                            placeholder="Search attribute..."
                                            value={searchTerm}
                                            onValueChange={(value) =>
                                              setSingleAttrSearch((prev) => ({ ...prev, [index]: value }))
                                            }
                                            onKeyDown={(e) => {
                                              if (e.key !== 'Enter') return
                                              e.preventDefault()

                                              const name = searchTerm.trim()
                                              if (!name) return

                                              const exact = (attributes ?? []).find(
                                                (a) => a.name.toLowerCase() === name.toLowerCase()
                                              )

                                              const next = [...singleAttributes]
                                              next[index].attributeId = exact ? exact.id : name
                                              next[index].values = []
                                              setSingleAttributes(next)
                                              setSingleAttrPopoverOpen((prev) => ({ ...prev, [index]: false }))
                                            }}
                                          />
                                          <CommandList>
                                            <CommandEmpty>No attribute found.</CommandEmpty>

                                            {searchTerm.trim() && !hasExactMatch ? (
                                              <CommandGroup heading="Create">
                                                <CommandItem
                                                  value={`create:${searchTerm.trim()}`}
                                                  onSelect={() => {
                                                    const name = searchTerm.trim()
                                                    if (!name) return
                                                    const next = [...singleAttributes]
                                                    next[index].attributeId = name
                                                    next[index].values = []
                                                    setSingleAttributes(next)
                                                    setSingleAttrPopoverOpen((prev) => ({ ...prev, [index]: false }))
                                                  }}
                                                >
                                                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                                                  Create "{searchTerm.trim()}"
                                                </CommandItem>
                                              </CommandGroup>
                                            ) : null}

                                            <CommandGroup heading="Existing Attributes">
                                              {(attributes ?? []).map((attr) => (
                                                <CommandItem
                                                  key={attr.id}
                                                  value={attr.name}
                                                  onSelect={() => {
                                                    const next = [...singleAttributes]
                                                    next[index].attributeId = attr.id
                                                    next[index].values = []
                                                    setSingleAttributes(next)
                                                    setSingleAttrPopoverOpen((prev) => ({ ...prev, [index]: false }))
                                                  }}
                                                >
                                                  <Check
                                                    className={cn(
                                                      'mr-2 h-4 w-4',
                                                      sel.attributeId === attr.id ? 'opacity-100' : 'opacity-0'
                                                    )}
                                                  />
                                                  {attr.name}
                                                </CommandItem>
                                              ))}
                                            </CommandGroup>
                                          </CommandList>
                                        </Command>
                                      </PopoverContent>
                                    </Popover>
                                  </div>

                                  {typeof sel.attributeId === 'string' ? (
                                    <Input
                                      placeholder="Enter attribute name"
                                      value={sel.attributeId}
                                      onChange={(e) => {
                                        const next = [...singleAttributes]
                                        next[index].attributeId = e.target.value
                                        setSingleAttributes(next)
                                      }}
                                      onKeyDown={(e) => {
                                        if (e.key !== 'Enter') return
                                        e.preventDefault()
                                        const next = [...singleAttributes]
                                        next[index].attributeId = String(next[index].attributeId).trim()
                                        setSingleAttributes(next)
                                      }}
                                      className="mt-2"
                                    />
                                  ) : null}
                                </div>

                                <div className="space-y-2">
                                  <Label>Values</Label>

                                  {isExisting && selectedAttr?.values?.length ? (
                                    <div className="flex flex-wrap gap-2 mb-2">
                                      {selectedAttr.values.map((v) => (
                                        <div
                                          key={v.id}
                                          className={cn(
                                            'cursor-pointer px-2 py-1 rounded-md text-xs border transition-colors',
                                            sel.values.includes(v.value)
                                              ? 'bg-primary text-primary-foreground border-primary'
                                              : 'bg-muted hover:bg-muted/80'
                                          )}
                                          onClick={() => {
                                            const next = [...singleAttributes]
                                            if (next[index].values.includes(v.value)) {
                                              next[index].values = next[index].values.filter((val) => val !== v.value)
                                            } else {
                                              next[index].values = [...next[index].values, v.value]
                                            }
                                            setSingleAttributes(next)
                                          }}
                                        >
                                          {v.value}
                                        </div>
                                      ))}
                                    </div>
                                  ) : null}

                                  <Input
                                    placeholder={isColor ? 'Green, Red, #000000' : '10, 12, 18, 20'}
                                    value={sel.values.join(', ')}
                                    onChange={(e) => {
                                      const next = [...singleAttributes]
                                      next[index].values = e.target.value.split(',').map((s) => s.trim())
                                      setSingleAttributes(next)
                                    }}
                                    onKeyDown={(e) => {
                                      if (e.key !== 'Enter') return
                                      e.preventDefault()
                                      const next = [...singleAttributes]
                                      next[index].values = sel.values.map((v) => v.trim()).filter(Boolean)
                                      setSingleAttributes(next)
                                    }}
                                    onBlur={(e) => {
                                      const next = [...singleAttributes]
                                      next[index].values = e.target.value
                                        .split(',')
                                        .map((s) => s.trim())
                                        .filter(Boolean)
                                      setSingleAttributes(next)
                                    }}
                                  />

                                  {isColor ? (
                                    <div className="flex gap-2 mt-2">
                                      <Input
                                        type="color"
                                        className="w-10 h-10 p-1 cursor-pointer"
                                        onChange={(e) => {
                                          const next = [...singleAttributes]
                                          next[index].values = [...next[index].values, e.target.value]
                                          setSingleAttributes(next)
                                        }}
                                        title="Pick a color to add"
                                      />
                                      <div className="text-xs text-muted-foreground flex items-center">
                                        Pick color to add hex code
                                      </div>
                                    </div>
                                  ) : null}
                                </div>

                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  onClick={() => {
                                    const next = [...singleAttributes]
                                    next.splice(index, 1)
                                    setSingleAttributes(next)
                                  }}
                                >
                                  <Icon icon="solar:trash-bin-trash-bold" className="text-red-500" />
                                </Button>
                              </div>
                            )
                          })}
                        </div>
                      ) : (
                        <div className="text-sm text-muted-foreground">No attributes selected yet.</div>
                      )}

                      <div className="flex gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          onClick={() => setSingleAttributes((prev) => [...prev, { attributeId: '', values: [] }])}
                        >
                          <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                          Add Attribute
                        </Button>
                      </div>
                    </div>
                  </TabsContent>

                  <TabsContent value="features" className="pt-4">
                    <div className="rounded-lg border p-3 space-y-3">
                      <div className="text-sm text-muted-foreground">Pulled from Product Settings → Features.</div>
                      {activeFeatures.length ? (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                          {activeFeatures.map((feat) => {
                            const current = featureValues[feat.id] ?? ''
                            if (feat.type === 'toggle') {
                              const checked = current === 'true'
                              return (
                                <div key={feat.id} className="flex items-center gap-2">
                                  <Checkbox
                                    checked={checked}
                                    onCheckedChange={(c) =>
                                      setFeatureValues((prev) => ({
                                        ...prev,
                                        [feat.id]: c === true ? 'true' : 'false',
                                      }))
                                    }
                                  />
                                  <Label
                                    className="cursor-pointer"
                                    onClick={() =>
                                      setFeatureValues((prev) => ({
                                        ...prev,
                                        [feat.id]: checked ? 'false' : 'true',
                                      }))
                                    }
                                  >
                                    {feat.name}
                                  </Label>
                                </div>
                              )
                            }

                            if (feat.type === 'select') {
                              return (
                                <div key={feat.id} className="space-y-2">
                                  <Label>{feat.name}</Label>
                                  <Select
                                    value={current || 'none'}
                                    onValueChange={(v) =>
                                      setFeatureValues((prev) => ({
                                        ...prev,
                                        [feat.id]: v === 'none' ? '' : v,
                                      }))
                                    }
                                  >
                                    <SelectTrigger>
                                      <SelectValue placeholder="Select" />
                                    </SelectTrigger>
                                    <SelectContent>
                                      <SelectItem value="none">None</SelectItem>
                                      {(feat.values || []).map((opt) => (
                                        <SelectItem key={opt.id} value={opt.value}>
                                          {opt.value}
                                        </SelectItem>
                                      ))}
                                    </SelectContent>
                                  </Select>
                                </div>
                              )
                            }

                            return (
                              <div key={feat.id} className="space-y-2">
                                <Label>{feat.name}</Label>
                                <Input
                                  value={current}
                                  onChange={(e) =>
                                    setFeatureValues((prev) => ({
                                      ...prev,
                                      [feat.id]: e.target.value,
                                    }))
                                  }
                                  placeholder="Enter value"
                                />
                              </div>
                            )
                          })}
                        </div>
                      ) : (
                        <div className="text-sm text-muted-foreground">No features yet.</div>
                      )}
                    </div>
                  </TabsContent>

                  <TabsContent value="custom" className="pt-4">
                    <div className="rounded-lg border p-3 space-y-3">
                      <div className="text-sm text-muted-foreground">Pulled from Product Settings → Meta.</div>
                      {activeCustomFields.length ? (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                          {activeCustomFields.map((field) => {
                            const value = customFieldValues[field.id] ?? ''
                            if (field.type === 'boolean') {
                              const checked = value === 'true'
                              return (
                                <div key={field.id} className="flex items-center gap-2">
                                  <Checkbox
                                    checked={checked}
                                    onCheckedChange={(c) =>
                                      setCustomFieldValues((prev) => ({
                                        ...prev,
                                        [field.id]: c === true ? 'true' : 'false',
                                      }))
                                    }
                                  />
                                  <Label
                                    className="cursor-pointer"
                                    onClick={() =>
                                      setCustomFieldValues((prev) => ({
                                        ...prev,
                                        [field.id]: checked ? 'false' : 'true',
                                      }))
                                    }
                                  >
                                    {field.name}{field.is_required ? ' *' : ''}
                                  </Label>
                                </div>
                              )
                            }

                            return (
                              <div key={field.id} className="space-y-2">
                                <Label>
                                  {field.name}{field.is_required ? ' *' : ''}
                                </Label>
                                <Input
                                  type={
                                    field.type === 'number'
                                      ? 'number'
                                      : field.type === 'date'
                                        ? 'date'
                                        : 'text'
                                  }
                                  value={value}
                                  onChange={(e) =>
                                    setCustomFieldValues((prev) => ({
                                      ...prev,
                                      [field.id]: e.target.value,
                                    }))
                                  }
                                  placeholder={field.type === 'text' ? 'Enter value' : undefined}
                                />
                              </div>
                            )
                          })}
                        </div>
                      ) : (
                        <div className="text-sm text-muted-foreground">No custom fields yet.</div>
                      )}
                    </div>
                  </TabsContent>
                </Tabs>
              </form>
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Media</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <AnimatedDropzone
                label="Main image"
                description="Upload main product image."
                accept="image/*"
                disabled={uploadMutation.isPending}
                onPickFiles={(files) => handlePickMainImage(files[0] ?? null)}
              />

              {formData.image ? (
                <div className="rounded-md border p-2">
                  <img src={formData.image} alt="Main" className="h-40 w-full object-cover rounded" />
                  <Button
                    type="button"
                    variant="ghost"
                    className="mt-2"
                    onClick={() => setFormData((p) => ({ ...p, image: '' }))}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                    Remove
                  </Button>
                </div>
              ) : null}

              <Separator />

              <AnimatedDropzone
                label="Gallery"
                description="Pick multiple images to add to the gallery."
                accept="image/*"
                multiple
                disabled={uploadMutation.isPending}
                onPickFiles={handlePickGalleryFiles}
              />

              {formData.gallery.length > 0 ? (
                <div className="grid grid-cols-3 gap-2">
                  {formData.gallery.slice(0, 9).map((url) => (
                    <button
                      key={url}
                      type="button"
                      className="rounded-md border p-1 text-left"
                      onClick={() => setFormData((p) => ({ ...p, gallery: p.gallery.filter((g) => g !== url) }))}
                      title="Remove"
                    >
                      <img src={url} alt="Gallery" className="h-20 w-full object-cover rounded" />
                    </button>
                  ))}
                </div>
              ) : null}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
