import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { useSettingsStore } from '@/store/settingsStore'
import { formatCurrency } from '@/lib/utils'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { Check, ChevronsUpDown } from 'lucide-react'
import { AnimatedDropzone } from '@/components/react-bits/AnimatedDropzone'
import { RichTextEditor } from '@/components/ui/RichTextEditor'

type TaxMode = 'default' | 'none' | 'custom' | 'tax'

type ProductType = 'product' | 'service'

interface Category {
  id: number
  name: string
  parent_id?: number | null
}

interface ProductUnit {
  id: number
  name: string
  short_name: string | null
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
  slug: string
  type: string
  is_active: boolean
  values?: ProductAttributeValue[]
}

interface ProductFeatureOption {
  id: number
  value: string
}

interface ProductFeature {
  id: number
  name: string
  slug: string
  type: 'text' | 'select' | 'toggle'
  is_active: boolean
  values?: ProductFeatureOption[]
}

interface ProductCustomField {
  id: number
  name: string
  slug: string
  type: 'text' | 'number' | 'date' | 'boolean'
  is_required: boolean
  default_value: string | null
  is_active: boolean
}

interface Tax {
  id: number
  name: string
  rate: number
  is_active: boolean
  is_inclusive: boolean
}

interface Supplier {
  id: number
  name: string
  code: string | null
  email: string | null
  phone: string | null
  is_active: boolean
}

interface ProductSupplierData {
  supplier_id: number
  supplier_sku: string
  cost: string
  is_preferred: boolean
  min_order_quantity: string
  lead_time_days: string
}

type ProductStatus = 'draft' | 'pending_approval' | 'active' | 'discontinued' | 'archived'
type TrackingMethod = 'none' | 'quantity' | 'batch' | 'serial'
type TopTabKey = 'product' | 'commercial' | 'inventory' | 'operations' | 'channels' | 'control' | 'media'

interface BarcodeDetectorWindow extends Window {
  BarcodeDetector?: new (options?: { formats?: string[] }) => {
    detect: (source: ImageBitmapSource) => Promise<Array<{ rawValue?: string }>>
  }
}

function slugSkuBase(name: string): string {
  const raw = (name || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '')
  return raw.slice(0, 8) || 'SKU'
}

function generateSku(name: string): string {
  const base = slugSkuBase(name)
  const rand = Math.random().toString(36).slice(2, 8).toUpperCase()
  return `${base}-${rand}`
}

function resolveImageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:') || path.startsWith('blob:')) return path
  if (path.startsWith('/storage/')) return path
  return `/storage/${path.replace(/^\//, '')}`
}

export function ProductCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const taxConfig = useSettingsStore((s) => s.tax)

  const [autoSku, setAutoSku] = useState(true)

  const [formData, setFormData] = useState({
    name: '',
    secondary_name: '',
    sku: '',
    barcode: '',
    type: 'product' as ProductType,
    category_id: '',
    unit: '',
    price: '',
    cost: '',
    wholesale_price: '',
    msrp: '',
    track_inventory: true,
    is_preorder: false,
    is_new_arrival: false,
    is_merchandise: false,
    show_on_website: false,
    preorder_available_at: '',
    stock_quantity: '',
    description: '',
    short_description: '',

    // New ERP fields
    status: 'draft' as ProductStatus,
    tracking_method: 'quantity' as TrackingMethod,
    reorder_point: '',
    reorder_quantity: '',
    shelf_life_days: '',
    
    // Physical attributes
    weight: '',
    weight_unit: 'kg',
    length: '',
    width: '',
    height: '',
    dimension_unit: 'cm',

    image: '' as string,
    gallery: [] as string[],

    tax_mode: 'default' as TaxMode,
    tax_included: false,
    tax_rate_override: '',
    tax_id: '',
  })

  // Suppliers state
  const [productSuppliers, setProductSuppliers] = useState<ProductSupplierData[]>([])

  const [hasVariants, setHasVariants] = useState(false)
  const [selectedAttributes, setSelectedAttributes] = useState<{ attributeId: number | string; values: string[] }[]>([])
  const [generatedVariants, setGeneratedVariants] = useState<any[]>([])
  const [variantAttrPopoverOpen, setVariantAttrPopoverOpen] = useState<Record<number, boolean>>({})
  const [variantAttrSearch, setVariantAttrSearch] = useState<Record<number, string>>({})

  const [singleAttributes, setSingleAttributes] = useState<{ attributeId: number | string; values: string[] }[]>([])
  const [singleAttrPopoverOpen, setSingleAttrPopoverOpen] = useState<Record<number, boolean>>({})
  const [singleAttrSearch, setSingleAttrSearch] = useState<Record<number, string>>({})

  const [unitPopoverOpen, setUnitPopoverOpen] = useState(false)
  const [unitSearch, setUnitSearch] = useState('')
  const [detailsTab, setDetailsTab] = useState('inventory')
  const [activeTopTab, setActiveTopTab] = useState<TopTabKey>('product')
  const [barcodeScannerOpen, setBarcodeScannerOpen] = useState(false)
  const [barcodeScannerLoading, setBarcodeScannerLoading] = useState(false)
  const [barcodeScannerError, setBarcodeScannerError] = useState<string | null>(null)

  const productSectionRef = useRef<HTMLDivElement | null>(null)
  const channelsSectionRef = useRef<HTMLDivElement | null>(null)
  const commercialSectionRef = useRef<HTMLDivElement | null>(null)
  const detailTabsRef = useRef<HTMLDivElement | null>(null)
  const controlSectionRef = useRef<HTMLDivElement | null>(null)
  const operationsSectionRef = useRef<HTMLDivElement | null>(null)
  const mediaSectionRef = useRef<HTMLDivElement | null>(null)
  const barcodeVideoRef = useRef<HTMLVideoElement | null>(null)
  const barcodeStreamRef = useRef<MediaStream | null>(null)
  const barcodeDetectIntervalRef = useRef<number | null>(null)
  const barcodeDetectLockRef = useRef(false)

  const stopBarcodeScanner = () => {
    if (barcodeDetectIntervalRef.current !== null) {
      window.clearInterval(barcodeDetectIntervalRef.current)
      barcodeDetectIntervalRef.current = null
    }

    if (barcodeStreamRef.current) {
      barcodeStreamRef.current.getTracks().forEach((track) => track.stop())
      barcodeStreamRef.current = null
    }

    if (barcodeVideoRef.current) {
      barcodeVideoRef.current.srcObject = null
    }

    setBarcodeScannerLoading(false)
    barcodeDetectLockRef.current = false
  }

  // Default to the same pricing mode as Settings
  useEffect(() => {
    setFormData((prev) => ({
      ...prev,
      tax_included: (taxConfig.pricing_mode ?? 'exclusive') === 'inclusive',
    }))
    // only run when settings changes
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [taxConfig.pricing_mode])

  useEffect(() => {
    if (!autoSku) return
    setFormData((prev) => ({
      ...prev,
      sku: prev.sku ? prev.sku : generateSku(prev.name),
    }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (!autoSku) return
    setFormData((prev) => ({ ...prev, sku: generateSku(prev.name) }))
  }, [autoSku, formData.name])

  useEffect(() => {
    if (!barcodeScannerOpen) {
      stopBarcodeScanner()
      return
    }

    let cancelled = false

    const startScanner = async () => {
      const BarcodeDetectorCtor = (window as BarcodeDetectorWindow).BarcodeDetector
      if (!BarcodeDetectorCtor) {
        setBarcodeScannerError('Camera barcode scanning is not supported in this browser.')
        return
      }

      if (!navigator.mediaDevices?.getUserMedia) {
        setBarcodeScannerError('Camera access is not available on this device.')
        return
      }

      try {
        setBarcodeScannerLoading(true)
        setBarcodeScannerError(null)

        const stream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode: { ideal: 'environment' },
          },
          audio: false,
        })

        if (cancelled) {
          stream.getTracks().forEach((track) => track.stop())
          return
        }

        barcodeStreamRef.current = stream

        if (barcodeVideoRef.current) {
          barcodeVideoRef.current.srcObject = stream
          await barcodeVideoRef.current.play()
        }

        const detector = new BarcodeDetectorCtor({
          formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'],
        })

        barcodeDetectIntervalRef.current = window.setInterval(async () => {
          if (!barcodeVideoRef.current || barcodeDetectLockRef.current) return
          if (barcodeVideoRef.current.readyState < 2) return

          barcodeDetectLockRef.current = true

          try {
            const results = await detector.detect(barcodeVideoRef.current)
            const scanned = results.find((item) => (item.rawValue || '').trim().length > 0)?.rawValue?.trim()

            if (!scanned) return

            setFormData((prev) => ({ ...prev, barcode: scanned }))
            setBarcodeScannerOpen(false)
            toast({
              title: 'Barcode scanned',
              description: `Detected: ${scanned}`,
            })
          } catch {
            // Ignore transient detection failures while the camera stream is active.
          } finally {
            barcodeDetectLockRef.current = false
          }
        }, 250)
      } catch {
        setBarcodeScannerError('Could not start camera. Check camera permission and try again.')
      } finally {
        if (!cancelled) {
          setBarcodeScannerLoading(false)
        }
      }
    }

    void startScanner()

    return () => {
      cancelled = true
      stopBarcodeScanner()
    }
  }, [barcodeScannerOpen, toast])

  const generateVariants = () => {
    if (selectedAttributes.length === 0) return

    const cartesian = (a: any[]) => a.reduce((a, b) => a.flatMap((d: any) => b.map((e: any) => [d, e].flat())), [[]]);
    
    const validAttrs = selectedAttributes.filter((a) => {
      if (!a.values.length) return false
      if (typeof a.attributeId === 'number') return a.attributeId > 0
      return a.attributeId.trim() !== ''
    })
    if (validAttrs.length === 0) return

    const attrValues = validAttrs.map(a => a.values)
    let combinations: string[][] = []
    if (validAttrs.length === 1) {
        combinations = validAttrs[0].values.map(v => [v])
    } else {
        combinations = cartesian(attrValues)
    }

    const newVariants = combinations.map((combo: string[]) => {
        const name = combo.join(' / ')
        const suffix = combo.map(s => s.substring(0, 3).toUpperCase()).join('-')
        const sku = formData.sku ? `${formData.sku}-${suffix}` : suffix
        
        return {
            name,
            sku,
            price: formData.price,
            cost: formData.cost,
            stock_quantity: 0,
        barcode: '',
            attributes: validAttrs.reduce((acc, curr, idx) => {
                const attrName = typeof curr.attributeId === 'number' 
                    ? (attributes?.find(a => a.id === curr.attributeId)?.name || `Attr${curr.attributeId}`)
                    : (curr.attributeId as string)
                acc[attrName] = combo[idx]
                return acc
            }, {} as Record<string, string>)
        }
    })
    setGeneratedVariants(newVariants)
  }

  const { data: categories } = useQuery<Category[]>({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
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

  const { data: taxes } = useQuery<Tax[]>({
    queryKey: ['taxes'],
    queryFn: async () => {
      const response = await api.get('/taxes')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: suppliers } = useQuery<Supplier[]>({
    queryKey: ['suppliers'],
    queryFn: async () => {
      const response = await api.get('/suppliers', { params: { per_page: 100, is_active: true } })
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

  const [featureValues, setFeatureValues] = useState<Record<number, string>>({})
  const [customFieldValues, setCustomFieldValues] = useState<Record<number, string>>({})

  useEffect(() => {
    // Default unit to first active unit or fallback to 'pcs'
    if (formData.unit) return
    const first = activeUnits[0]
    setFormData((prev) => ({
      ...prev,
      unit: first ? String(first.short_name || first.name) : 'pcs',
    }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeUnits.length])

  useEffect(() => {
    // Seed custom fields with default values
    if (!activeCustomFields.length) return
    setCustomFieldValues((prev) => {
      const next = { ...prev }
      for (const field of activeCustomFields) {
        if (next[field.id] != null) continue
        next[field.id] = field.default_value ?? ''
      }
      return next
    })
  }, [activeCustomFields])

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

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/products', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      toast({ title: 'Product created successfully' })
      navigate('/products')
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to create product' })
    },
  })

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      const response = await api.post('/products/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as { created: number; errors: Array<{ row: number; message: string }> }
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      const errorCount = data.errors?.length || 0
      toast({
        title: `Import complete: ${data.created} created${errorCount ? `, ${errorCount} errors` : ''}`,
      })
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Import failed' })
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
        // stop after first failure to avoid partial confusion
        toast({ variant: 'destructive', title: 'Failed to upload a gallery image' })
        break
      }
    }

    if (nextUrls.length > 0) {
      setFormData((prev) => ({ ...prev, gallery: [...prev.gallery, ...nextUrls] }))
      toast({ title: `Uploaded ${nextUrls.length} gallery image(s)` })
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const finalCategoryId = formData.category_id.trim()

    const payloadFeatureValues = Object.entries(featureValues)
      .map(([featureId, value]) => ({
        product_feature_id: Number(featureId),
        value: value,
      }))
      .filter((x) => Number.isFinite(x.product_feature_id) && x.value !== '')

    const payloadCustomFieldValues = Object.entries(customFieldValues)
      .map(([fieldId, value]) => ({
        product_custom_field_id: Number(fieldId),
        value: value,
      }))
      .filter((x) => Number.isFinite(x.product_custom_field_id) && x.value !== '')

    const payloadAttributes = !hasVariants
      ? singleAttributes
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
      : []

    // If variants are enabled, use the first variant's price/cost as base, or 0 if none
    const baseCost = hasVariants 
        ? (generatedVariants.length > 0 ? parseFloat(generatedVariants[0].cost) : 0)
        : parseFloat(formData.cost)
    
    const basePrice = hasVariants
        ? (generatedVariants.length > 0 ? parseFloat(generatedVariants[0].price) : 0)
        : parseFloat(formData.price)

    // If variants are enabled, stock quantity of main product is sum of variants or 0
    const baseStock = hasVariants
        ? generatedVariants.reduce((acc, v) => acc + (parseInt(v.stock_quantity) || 0), 0)
        : (formData.track_inventory ? parseInt(formData.stock_quantity) : null)

    createMutation.mutate({
      name: formData.name,
      secondary_name: formData.secondary_name || null,
      sku: formData.sku,
      barcode: formData.barcode || null,
      type: formData.type,
      category_id: finalCategoryId ? parseInt(finalCategoryId) : null,
      cost: Number.isFinite(baseCost) ? baseCost : 0,
      price: Number.isFinite(basePrice) ? basePrice : 0,
      wholesale_price: formData.wholesale_price ? parseFloat(formData.wholesale_price) : null,
      msrp: formData.msrp ? parseFloat(formData.msrp) : null,
      track_inventory: !!formData.track_inventory,
      is_preorder: !!formData.is_preorder,
      is_new_arrival: !!formData.is_new_arrival,
      is_merchandise: !!formData.is_merchandise,
      show_on_website: !!formData.show_on_website,
      preorder_available_at: formData.preorder_available_at ? formData.preorder_available_at : null,
      stock_quantity: baseStock,
      description: formData.description || null,
      short_description: formData.short_description || null,
      image: formData.image || null,
      gallery: formData.gallery || [],

      unit: formData.unit || null,

      variants: hasVariants ? generatedVariants : [],

      tax_mode: formData.tax_mode,
      tax_included: !!formData.tax_included,
      tax_rate_override:
        formData.tax_mode === 'custom' && formData.tax_rate_override !== ''
          ? parseFloat(formData.tax_rate_override)
          : null,
      tax_id: formData.tax_mode === 'tax' && formData.tax_id ? parseInt(formData.tax_id) : null,
      is_active: true,

      // New ERP fields
      status: formData.status,
      tracking_method: formData.tracking_method,
      reorder_point: formData.reorder_point ? parseInt(formData.reorder_point) : null,
      reorder_quantity: formData.reorder_quantity ? parseInt(formData.reorder_quantity) : null,
      shelf_life_days: formData.shelf_life_days ? parseInt(formData.shelf_life_days) : null,
      weight: formData.weight ? parseFloat(formData.weight) : null,
      weight_unit: formData.weight_unit || null,
      length: formData.length ? parseFloat(formData.length) : null,
      width: formData.width ? parseFloat(formData.width) : null,
      height: formData.height ? parseFloat(formData.height) : null,
      dimension_unit: formData.dimension_unit || null,

      // Suppliers
      suppliers: productSuppliers.filter(s => s.supplier_id).map(s => ({
        supplier_id: s.supplier_id,
        supplier_sku: s.supplier_sku || null,
        cost: s.cost ? parseFloat(s.cost) : null,
        is_preferred: s.is_preferred,
        moq: s.min_order_quantity ? parseInt(s.min_order_quantity) : 1,
        lead_time_days: s.lead_time_days ? parseInt(s.lead_time_days) : null,
      })),

      feature_values: payloadFeatureValues,
      custom_field_values: payloadCustomFieldValues,

      attributes: payloadAttributes,
    })
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

    // default
    if (typeof taxConfig.default_tax_rate === 'number') return taxConfig.default_tax_rate
    if (taxConfig.default_tax_id) {
      const match = taxes?.find((t) => t.id === taxConfig.default_tax_id)
      return match?.rate ?? 0
    }
    return 0
  }, [formData.tax_mode, formData.tax_rate_override, formData.tax_id, taxes, taxConfig.default_tax_rate, taxConfig.default_tax_id])

  // Determine if the selected tax is inclusive based on the tax's own setting
  const selectedTaxIsInclusive = useMemo(() => {
    if (formData.tax_mode === 'none') return false
    if (formData.tax_mode === 'custom') return formData.tax_included // For custom, use the form setting
    if (formData.tax_mode === 'tax' && formData.tax_id) {
      const match = taxes?.find((t) => t.id === Number(formData.tax_id))
      return match?.is_inclusive ?? false
    }
    // Default mode - use the default tax's is_inclusive setting
    if (taxConfig.default_tax_id) {
      const match = taxes?.find((t) => t.id === taxConfig.default_tax_id)
      return match?.is_inclusive ?? false
    }
    return false
  }, [formData.tax_mode, formData.tax_id, formData.tax_included, taxes, taxConfig.default_tax_id])

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

    if (selectedTaxIsInclusive) {
      const net = price / (1 + r)
      const tax = price - net
      return { net, tax, gross: price }
    }

    const tax = price * r
    return { net: price, tax, gross: price + tax }
  }, [formData.price, selectedTaxIsInclusive, selectedTaxRate])

  const taxSelectValue = useMemo(() => {
    if (formData.tax_mode === 'tax' && formData.tax_id) return `tax:${formData.tax_id}`
    return formData.tax_mode
  }, [formData.tax_mode, formData.tax_id])

  const scrollToSection = (ref: { current: HTMLDivElement | null }) => {
    ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const handleTopTabClick = (tab: TopTabKey) => {
    setActiveTopTab(tab)

    if (tab === 'product') {
      scrollToSection(productSectionRef)
      return
    }

    if (tab === 'commercial') {
      setTimeout(() => {
        if (!hasVariants && commercialSectionRef.current) {
          scrollToSection(commercialSectionRef)
          return
        }
        scrollToSection(detailTabsRef)
      }, 50)
      return
    }

    if (tab === 'inventory') {
      setDetailsTab('inventory')
      setTimeout(() => scrollToSection(detailTabsRef), 50)
      return
    }

    if (tab === 'operations') {
      setDetailsTab('inventory')
      setTimeout(() => scrollToSection(operationsSectionRef), 50)
      return
    }

    if (tab === 'channels') {
      scrollToSection(channelsSectionRef)
      return
    }

    if (tab === 'control') {
      setDetailsTab('features')
      setTimeout(() => scrollToSection(controlSectionRef), 50)
      return
    }

    if (tab === 'media') {
      scrollToSection(mediaSectionRef)
    }
  }

  return (
    <div className="mx-auto max-w-[1500px] space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Link to="/products" className="hover:underline">Products</Link>
            <span>/</span>
            <span className="text-foreground">Add Product</span>
          </div>
          <h1 className="text-3xl font-bold mt-1">Add Product</h1>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" asChild>
            <Link to="/products">
              <Icon icon="solar:alt-arrow-left-bold" className="mr-2 h-4 w-4" />
              Back
            </Link>
          </Button>
          <Button type="submit" form="product-form" disabled={createMutation.isPending}>
            <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
            Save
          </Button>
        </div>
      </div>

      <Card className="border-2 border-primary/25 bg-gradient-to-b from-white to-blue-50/40 shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base text-green-700">Quick Create Mode</CardTitle>
          <div className="text-sm text-muted-foreground">
            Default SME-friendly entry. Create the product quickly, then complete more details later.
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="quick-name">Product Name</Label>
              <Input
                id="quick-name"
                placeholder="e.g. Mineral Water 500ml"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="quick-unit">Unit</Label>
              <Input
                id="quick-unit"
                placeholder="e.g. pcs"
                value={formData.unit}
                onChange={(e) => setFormData({ ...formData, unit: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="quick-price">Selling Price</Label>
              <Input
                id="quick-price"
                type="number"
                step="0.01"
                placeholder="0.00"
                value={formData.price}
                onChange={(e) => setFormData({ ...formData, price: e.target.value })}
              />
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button type="submit" form="product-form" disabled={createMutation.isPending}>Save Product</Button>
            <Button type="button" variant="secondary" onClick={() => handleTopTabClick('product')}>Save & Add Details</Button>
          </div>
        </CardContent>
      </Card>

      <div className="sticky top-0 z-40 max-w-[calc(100%-364px)] border-b border-[#dfe6f2] bg-[#f6f8fc] py-3 shadow-sm max-lg:max-w-none">
        <div className="rounded-xl bg-[#eef3fb] p-2">
          <div className="grid grid-cols-2 gap-2 text-sm font-medium md:grid-cols-4 xl:grid-cols-7">
          {([
            { key: 'product', label: 'Product' },
            { key: 'commercial', label: 'Commercial' },
            { key: 'inventory', label: 'Inventory' },
            { key: 'operations', label: 'Operations' },
            { key: 'channels', label: 'Channels' },
            { key: 'control', label: 'Control' },
            { key: 'media', label: 'Media' },
          ] as Array<{ key: TopTabKey; label: string }>).map((tab) => (
            <button
              key={tab.key}
              type="button"
              onClick={() => handleTopTabClick(tab.key)}
              className={cn(
                'rounded-md px-3 py-2 text-center transition-colors',
                activeTopTab === tab.key
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
              )}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>
      </div>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-6">
          <div ref={productSectionRef}>
          <Card>
            <CardHeader>
              <div className="mb-1 inline-flex w-fit rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-primary">Tab 1</div>
              <CardTitle>Product</CardTitle>
            </CardHeader>
            <CardContent>
              <form id="product-form" onSubmit={handleSubmit} className="space-y-5">
                <div className="space-y-4">
                  <h3 className="text-lg font-semibold">Basic Info</h3>
                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                      <Label htmlFor="name">Product Name *</Label>
                      <Input
                        id="name"
                        value={formData.name}
                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        required
                      />
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="secondary_name">Secondary Name</Label>
                      <Input
                        id="secondary_name"
                        value={formData.secondary_name}
                        onChange={(e) => setFormData({ ...formData, secondary_name: e.target.value })}
                        placeholder="e.g. 500ml / Small / Variant label"
                      />
                    </div>

                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <Label htmlFor="sku">SKU</Label>
                      <div className="flex items-center gap-2">
                        <Checkbox
                          checked={autoSku}
                          onCheckedChange={(checked) => {
                            const on = checked === true
                            setAutoSku(on)
                            if (on) {
                              setFormData((prev) => ({ ...prev, sku: generateSku(prev.name) }))
                            }
                          }}
                        />
                        <span className="text-xs text-muted-foreground">Auto SKU</span>
                      </div>
                    </div>
                    <Input
                      id="sku"
                      value={formData.sku}
                      onChange={(e) => setFormData({ ...formData, sku: e.target.value })}
                      disabled={autoSku}
                      required
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="barcode">Barcode (optional)</Label>
                    <div className="relative">
                      <Input
                        id="barcode"
                        value={formData.barcode}
                        onChange={(e) => setFormData({ ...formData, barcode: e.target.value })}
                        className="pr-10"
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => setBarcodeScannerOpen(true)}
                        className="absolute right-1 top-1/2 h-8 w-8 -translate-y-1/2"
                        title="Scan barcode with camera"
                        aria-label="Scan barcode with camera"
                      >
                        <Icon icon="solar:camera-bold" className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>

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
                            "w-full justify-between",
                            !formData.category_id && "text-muted-foreground"
                          )}
                        >
                          {formData.category_id
                            ? categoryOptions.find(
                                (cat) => String(cat.id) === formData.category_id
                              )?.name || categoryPath(categoryOptions.find((cat) => String(cat.id) === formData.category_id)!)
                            : "Select category"}
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
                                onSelect={() => {
                                  setFormData({ ...formData, category_id: '' })
                                }}
                              >
                                <Check
                                  className={cn(
                                    "mr-2 h-4 w-4",
                                    formData.category_id === "" ? "opacity-100" : "opacity-0"
                                  )}
                                />
                                None
                              </CommandItem>
                              {categoryOptions.map((cat) => (
                                <CommandItem
                                  key={cat.id}
                                  value={categoryPath(cat)}
                                  onSelect={() => {
                                    setFormData({ ...formData, category_id: String(cat.id) })
                                  }}
                                >
                                  <Check
                                    className={cn(
                                      "mr-2 h-4 w-4",
                                      formData.category_id === String(cat.id) ? "opacity-100" : "opacity-0"
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

                  <div className="space-y-2">
                    <Label>Unit</Label>
                    <Popover open={unitPopoverOpen} onOpenChange={setUnitPopoverOpen}>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          role="combobox"
                          className={cn(
                            'w-full justify-between',
                            !formData.unit && 'text-muted-foreground'
                          )}
                        >
                          {(() => {
                            const current = (activeUnits.length ? activeUnits : [])
                              .find((u) => String(u.short_name || u.name) === (formData.unit || ''))
                            if (current) {
                              const label = current.short_name ? `${current.name} (${current.short_name})` : current.name
                              return label
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
                </div>
                </div>

                <Separator />

                <div className="space-y-4">
                  <h3 className="text-lg font-semibold">Description</h3>
                <div className="space-y-2">
                  <Label htmlFor="short_description">Short Description</Label>
                  <p className="text-xs text-muted-foreground">Displayed beside the product image in the storefront. Keep it concise (1–3 lines).</p>
                  <RichTextEditor
                    value={formData.short_description}
                    onChange={(html) => setFormData({ ...formData, short_description: html })}
                    placeholder="Brief product summary shown beside the product image…"
                    minHeight="120px"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="description">Full Description</Label>
                  <p className="text-xs text-muted-foreground">Appears in the Description tab on the product page. Supports rich formatting, images, and videos.</p>
                  <RichTextEditor
                    value={formData.description}
                    onChange={(html) => setFormData({ ...formData, description: html })}
                    placeholder="Full product description with details, features, and media…"
                    minHeight="260px"
                  />
                </div>
                </div>

                <Separator />

                <div className="flex items-center gap-2">
                  <Checkbox
                    checked={hasVariants}
                    onCheckedChange={(checked) => setHasVariants(checked === true)}
                  />
                  <Label
                    className="cursor-pointer"
                    onClick={() => setHasVariants(!hasVariants)}
                  >
                    This product has variants
                  </Label>
                </div>

                {!hasVariants ? (
                  <div className="space-y-4">
                    <div className="space-y-4">
                      <h3 className="text-lg font-semibold">Pricing</h3>
                      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <div className="space-y-2">
                          <Label htmlFor="cost">Cost Price</Label>
                          <Input
                            id="cost"
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={formData.cost}
                            onChange={(e) => setFormData({ ...formData, cost: e.target.value })}
                          />
                          <p className="text-xs text-muted-foreground">Your purchase cost</p>
                        </div>
                        <div className="space-y-2">
                          <Label htmlFor="price">Selling Price *</Label>
                          <Input
                            id="price"
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={formData.price}
                            onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                            required
                          />
                          <p className="text-xs text-muted-foreground">Retail price</p>
                        </div>
                        <div className="space-y-2">
                          <Label htmlFor="wholesale_price">Wholesale Price</Label>
                          <Input
                            id="wholesale_price"
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={formData.wholesale_price}
                            onChange={(e) => setFormData({ ...formData, wholesale_price: e.target.value })}
                          />
                          <p className="text-xs text-muted-foreground">B2B/bulk price</p>
                        </div>
                        <div className="space-y-2">
                          <Label htmlFor="msrp">MSRP</Label>
                          <Input
                            id="msrp"
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={formData.msrp}
                            onChange={(e) => setFormData({ ...formData, msrp: e.target.value })}
                          />
                          <p className="text-xs text-muted-foreground">Manufacturer's suggested price</p>
                        </div>
                      </div>
                      {formData.cost && formData.price && parseFloat(formData.cost) > 0 && parseFloat(formData.price) > 0 && (
                        <div className="flex items-center gap-4 p-3 bg-muted/50 rounded-lg">
                          <div className="text-sm">
                            <span className="text-muted-foreground">Profit Margin: </span>
                            <span className="font-medium text-green-600">
                              {(((parseFloat(formData.price) - parseFloat(formData.cost)) / parseFloat(formData.price)) * 100).toFixed(1)}%
                            </span>
                          </div>
                          <div className="text-sm">
                            <span className="text-muted-foreground">Markup: </span>
                            <span className="font-medium">
                              {(((parseFloat(formData.price) - parseFloat(formData.cost)) / parseFloat(formData.cost)) * 100).toFixed(1)}%
                            </span>
                          </div>
                        </div>
                      )}
                    </div>

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
                  </div>
                ) : (
                  <div className="space-y-6 border rounded-md p-4 bg-muted/10">
                    <div className="space-y-4">
                      <h3 className="font-medium">Attributes</h3>
                      <div className="text-sm text-muted-foreground">
                        Select attributes (e.g. Size, Color) to generate variants.
                      </div>
                      
                      <div className="space-y-4">
                        {selectedAttributes.map((sel, index) => {
                            const isExisting = typeof sel.attributeId === 'number'
                            const selectedAttr = isExisting ? attributes?.find(a => a.id === sel.attributeId) : null
                            const isColor = selectedAttr?.name.toLowerCase() === 'color' || (typeof sel.attributeId === 'string' && sel.attributeId.toLowerCase() === 'color')
                          const searchTerm = variantAttrSearch[index] ?? ''
                          const hasExactMatch = (attributes ?? []).some((a) => a.name.toLowerCase() === searchTerm.trim().toLowerCase())

                            return (
                            <div key={index} className="grid grid-cols-1 md:grid-cols-3 gap-4 items-start border p-3 rounded-md bg-background">
                            <div className="space-y-2">
                                <Label>Attribute</Label>
                                <div className="flex gap-2">
                              <Popover
                                open={!!variantAttrPopoverOpen[index]}
                                onOpenChange={(open) =>
                                setVariantAttrPopoverOpen((prev) => ({ ...prev, [index]: open }))
                                }
                              >
                                        <PopoverTrigger asChild>
                                            <Button
                                            type="button"
                                                variant="outline"
                                                role="combobox"
                                                className="w-full justify-between"
                                            >
                                                {isExisting 
                                                    ? selectedAttr?.name 
                                                    : (sel.attributeId ? sel.attributeId : "Select Attribute")}
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-[200px] p-0">
                                            <Command>
                                                <CommandInput
                                      placeholder="Search attribute..."
                                      value={searchTerm}
                                      onValueChange={(value) =>
                                      setVariantAttrSearch((prev) => ({ ...prev, [index]: value }))
                                      }
                                      onKeyDown={(e) => {
                                      if (e.key !== 'Enter') return
                                      e.preventDefault()

                                                    const name = searchTerm.trim()
                                                    if (!name) return

                                                    const exact = (attributes ?? []).find(
                                                      (a) => a.name.toLowerCase() === name.toLowerCase()
                                                    )

                                                    const newAttrs = [...selectedAttributes]
                                                    newAttrs[index].attributeId = exact ? exact.id : name
                                                    newAttrs[index].values = []
                                                    setSelectedAttributes(newAttrs)
                                                    setVariantAttrPopoverOpen((prev) => ({ ...prev, [index]: false }))
                                      }}
                                    />
                                                <CommandList>
                                                    <CommandEmpty>
                                        No attribute found.
                                                    </CommandEmpty>

                                      {searchTerm.trim() && !hasExactMatch ? (
                                        <CommandGroup heading="Create">
                                        <CommandItem
                                          value={`create:${searchTerm.trim()}`}
                                          onSelect={() => {
                                          const name = searchTerm.trim()
                                          if (!name) return
                                          const newAttrs = [...selectedAttributes]
                                          newAttrs[index].attributeId = name
                                          newAttrs[index].values = []
                                          setSelectedAttributes(newAttrs)
                                          setVariantAttrPopoverOpen((prev) => ({ ...prev, [index]: false }))
                                          }}
                                        >
                                          <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                                          Create "{searchTerm.trim()}"
                                        </CommandItem>
                                        </CommandGroup>
                                      ) : null}

                                                    <CommandGroup heading="Existing Attributes">
                                                        {attributes?.map((attr) => (
                                                            <CommandItem
                                                                key={attr.id}
                                                                value={attr.name}
                                                                onSelect={() => {
                                                                    const newAttrs = [...selectedAttributes]
                                                                    newAttrs[index].attributeId = attr.id
                                                                    newAttrs[index].values = [] // Reset values when attr changes
                                                                    setSelectedAttributes(newAttrs)
                                              setVariantAttrPopoverOpen((prev) => ({ ...prev, [index]: false }))
                                                                }}
                                                            >
                                                                <Check
                                                                    className={cn(
                                                                        "mr-2 h-4 w-4",
                                                                        sel.attributeId === attr.id ? "opacity-100" : "opacity-0"
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
                            {typeof sel.attributeId === 'string' && (
                                    <Input 
                                        placeholder="Enter attribute name" 
                                        value={sel.attributeId}
                                        onChange={(e) => {
                                            const newAttrs = [...selectedAttributes]
                                            newAttrs[index].attributeId = e.target.value
                                            setSelectedAttributes(newAttrs)
                                        }}
                                onKeyDown={(e) => {
                                  if (e.key !== 'Enter') return
                                  // prevent submitting the whole product form
                                  e.preventDefault()
                                  const newAttrs = [...selectedAttributes]
                                  newAttrs[index].attributeId = String(newAttrs[index].attributeId).trim()
                                  setSelectedAttributes(newAttrs)
                                }}
                                        className="mt-2"
                                    />
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>Values</Label>
                                {isExisting && selectedAttr?.values && selectedAttr.values.length > 0 && (
                                    <div className="flex flex-wrap gap-2 mb-2">
                                        {selectedAttr.values.map(v => (
                                            <div 
                                                key={v.id} 
                                                className={cn(
                                                    "cursor-pointer px-2 py-1 rounded-md text-xs border transition-colors",
                                                    sel.values.includes(v.value) 
                                                        ? "bg-primary text-primary-foreground border-primary" 
                                                        : "bg-muted hover:bg-muted/80"
                                                )}
                                                onClick={() => {
                                                    const newAttrs = [...selectedAttributes]
                                                    if (newAttrs[index].values.includes(v.value)) {
                                                        newAttrs[index].values = newAttrs[index].values.filter(val => val !== v.value)
                                                    } else {
                                                        newAttrs[index].values.push(v.value)
                                                    }
                                                    setSelectedAttributes(newAttrs)
                                                }}
                                            >
                                                {v.value}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <Input
                                    placeholder={isColor ? "Red, #FF0000, Blue" : "S, M, L or Red, Blue"}
                                    value={sel.values.join(', ')}
                                    onChange={(e) => {
                                        const newAttrs = [...selectedAttributes]
                                        newAttrs[index].values = e.target.value.split(',').map(s => s.trim())
                                        setSelectedAttributes(newAttrs)
                                    }}
                                  onKeyDown={(e) => {
                                    if (e.key !== 'Enter') return
                                    // prevent submitting the whole product form
                                    e.preventDefault()
                                    const newAttrs = [...selectedAttributes]
                                    newAttrs[index].values = sel.values.map((v) => v.trim()).filter(Boolean)
                                    setSelectedAttributes(newAttrs)
                                  }}
                                    onBlur={(e) => {
                                        const newAttrs = [...selectedAttributes]
                                        newAttrs[index].values = e.target.value.split(',').map(s => s.trim()).filter(Boolean)
                                        setSelectedAttributes(newAttrs)
                                    }}
                                />
                                {isColor && (
                                    <div className="flex gap-2 mt-2">
                                        <Input 
                                            type="color" 
                                            className="w-10 h-10 p-1 cursor-pointer"
                                            onChange={(e) => {
                                                const newAttrs = [...selectedAttributes]
                                                newAttrs[index].values.push(e.target.value)
                                                setSelectedAttributes(newAttrs)
                                            }}
                                            title="Pick a color to add"
                                        />
                                        <div className="text-xs text-muted-foreground flex items-center">
                                            Pick color to add hex code
                                        </div>
                                    </div>
                                )}
                            </div>
                            <Button type="button" variant="ghost" size="icon" onClick={() => {
                                const newAttrs = [...selectedAttributes]
                                newAttrs.splice(index, 1)
                                setSelectedAttributes(newAttrs)
                            }}>
                                <Icon icon="solar:trash-bin-trash-bold" className="text-red-500" />
                            </Button>
                            </div>
                        )})}

                        <div className="flex gap-2">
                            <Button
                              type="button"
                              variant="outline"
                              onClick={() => setSelectedAttributes([...selectedAttributes, { attributeId: '', values: [] }])}
                            >
                                <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                                Add Attribute
                            </Button>
                            <Button type="button" onClick={generateVariants} disabled={selectedAttributes.length === 0}>
                                Generate Variants
                            </Button>
                        </div>
                      </div>

                      {generatedVariants.length > 0 && (
                        <div className="mt-6 space-y-2">
                            <h3 className="font-medium">Variants Preview</h3>
                            <div className="border rounded-md overflow-hidden">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Variant</TableHead>
                                            <TableHead>SKU</TableHead>
                                            <TableHead>Price</TableHead>
                                            <TableHead>Stock</TableHead>
                                        <TableHead>Barcode</TableHead>
                                            <TableHead></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {generatedVariants.map((variant, idx) => (
                                            <TableRow key={idx}>
                                                <TableCell>{variant.name}</TableCell>
                                                <TableCell>
                                                    <Input 
                                                        value={variant.sku} 
                                                        onChange={(e) => {
                                                            const newVars = [...generatedVariants]
                                                            newVars[idx].sku = e.target.value
                                                            setGeneratedVariants(newVars)
                                                        }}
                                                        className="h-8 w-32"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Input 
                                                        type="number"
                                                        value={variant.price} 
                                                        onChange={(e) => {
                                                            const newVars = [...generatedVariants]
                                                            newVars[idx].price = e.target.value
                                                            setGeneratedVariants(newVars)
                                                        }}
                                                        className="h-8 w-24"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Input 
                                                        type="number"
                                                        value={variant.stock_quantity} 
                                                        onChange={(e) => {
                                                            const newVars = [...generatedVariants]
                                                            newVars[idx].stock_quantity = e.target.value
                                                            setGeneratedVariants(newVars)
                                                        }}
                                                        className="h-8 w-24"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                  <Input
                                                    value={variant.barcode ?? ''}
                                                    onChange={(e) => {
                                                      const newVars = [...generatedVariants]
                                                      newVars[idx].barcode = e.target.value
                                                      setGeneratedVariants(newVars)
                                                    }}
                                                    placeholder="Barcode"
                                                    className="h-8 w-32"
                                                  />
                                                </TableCell>
                                                <TableCell>
                                                    <Button type="button" variant="ghost" size="icon" onClick={() => {
                                                        const newVars = [...generatedVariants]
                                                        newVars.splice(idx, 1)
                                                        setGeneratedVariants(newVars)
                                                    }}>
                                                        <Icon icon="solar:trash-bin-trash-bold" className="text-red-500 h-4 w-4" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                <Separator />

                <div ref={commercialSectionRef} className="space-y-5 rounded-lg border p-4">
                  <div>
                    <div className="mb-1 inline-flex w-fit rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-primary">Tab 2</div>
                    <h2 className="text-2xl font-semibold">Commercial</h2>
                  </div>

                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                      <Label>Selling Price</Label>
                      <Input
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        value={formData.price}
                        onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label>Cost Price</Label>
                      <Input
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        value={formData.cost}
                        onChange={(e) => setFormData({ ...formData, cost: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="space-y-4 border-t pt-5">
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Suppliers</h3>
                        <p className="text-xs text-muted-foreground">Link suppliers who can provide this product.</p>
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setProductSuppliers([...productSuppliers, {
                          supplier_id: 0,
                          supplier_sku: '',
                          cost: '',
                          is_preferred: productSuppliers.length === 0,
                          min_order_quantity: '1',
                          lead_time_days: '',
                        }])}
                      >
                        <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                        Add Supplier
                      </Button>
                    </div>

                    {productSuppliers.length === 0 ? (
                      <div className="rounded-lg border border-dashed py-8 text-center text-muted-foreground">
                        <Icon icon="solar:box-bold" className="mx-auto mb-2 h-12 w-12 opacity-50" />
                        <p>No suppliers linked yet.</p>
                        <p className="text-xs">Add suppliers to track costs and lead times.</p>
                      </div>
                    ) : (
                      <div className="space-y-3">
                        {productSuppliers.map((ps, idx) => (
                          <div key={idx} className="grid grid-cols-1 gap-3 rounded-md border bg-muted/20 p-3 md:grid-cols-6">
                            <div className="space-y-1 md:col-span-2">
                              <Label className="text-xs">Supplier</Label>
                              <Select
                                value={ps.supplier_id ? String(ps.supplier_id) : ''}
                                onValueChange={(v) => {
                                  const next = [...productSuppliers]
                                  next[idx].supplier_id = parseInt(v) || 0
                                  setProductSuppliers(next)
                                }}
                              >
                                <SelectTrigger>
                                  <SelectValue placeholder="Select supplier" />
                                </SelectTrigger>
                                <SelectContent>
                                  {(suppliers || []).map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                      {s.name} {s.code ? `(${s.code})` : ''}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </div>

                            <div className="space-y-1">
                              <Label className="text-xs">Supplier SKU</Label>
                              <Input
                                value={ps.supplier_sku}
                                onChange={(e) => {
                                  const next = [...productSuppliers]
                                  next[idx].supplier_sku = e.target.value
                                  setProductSuppliers(next)
                                }}
                                placeholder="SKU"
                              />
                            </div>

                            <div className="space-y-1">
                              <Label className="text-xs">Cost</Label>
                              <Input
                                type="number"
                                step="0.01"
                                value={ps.cost}
                                onChange={(e) => {
                                  const next = [...productSuppliers]
                                  next[idx].cost = e.target.value
                                  setProductSuppliers(next)
                                }}
                                placeholder="0.00"
                              />
                            </div>

                            <div className="space-y-1">
                              <Label className="text-xs">Lead Time</Label>
                              <Input
                                type="number"
                                value={ps.lead_time_days}
                                onChange={(e) => {
                                  const next = [...productSuppliers]
                                  next[idx].lead_time_days = e.target.value
                                  setProductSuppliers(next)
                                }}
                                placeholder="7"
                              />
                            </div>

                            <div className="flex items-end gap-2">
                              <div className="flex items-center gap-2">
                                <Checkbox
                                  checked={ps.is_preferred}
                                  onCheckedChange={(checked) => {
                                    const next = productSuppliers.map((p, i) => ({
                                      ...p,
                                      is_preferred: i === idx ? checked === true : false,
                                    }))
                                    setProductSuppliers(next)
                                  }}
                                />
                                <Label className="text-xs">Preferred</Label>
                              </div>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => {
                                  const next = [...productSuppliers]
                                  next.splice(idx, 1)
                                  setProductSuppliers(next)
                                }}
                              >
                                <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                              </Button>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  <div className="space-y-4 border-t pt-5">
                    <h3 className="text-lg font-semibold">Tax</h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div className="space-y-2">
                        <Label>Tax Option</Label>
                        <Select
                          value={taxSelectValue}
                          onValueChange={(v) => {
                            if (v.startsWith('tax:')) {
                              const id = v.split(':')[1] || ''
                              setFormData({ ...formData, tax_mode: 'tax', tax_id: id, tax_rate_override: '' })
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
                              Default tax{typeof taxConfig.default_tax_rate === 'number' ? ` — ${taxConfig.default_tax_rate}%` : ''}
                            </SelectItem>
                            <SelectItem value="none">No tax</SelectItem>
                            <SelectItem value="custom">Custom tax rate</SelectItem>
                            {(taxes || []).filter((t) => t.is_active).map((t) => (
                              <SelectItem key={t.id} value={`tax:${t.id}`}>
                                {t.name} — {t.rate}%
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <div className="text-xs text-muted-foreground">Default tax comes from Settings and applies to products.</div>
                      </div>
                      <div className="space-y-2">
                        <Label>Effective Tax Rate</Label>
                        <Input readOnly value={`${selectedTaxRate}% (${selectedTaxIsInclusive ? 'Inclusive' : 'Exclusive'})`} />
                      </div>
                    </div>

                    {formData.tax_mode === 'custom' ? (
                      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                          <Label>Tax Method</Label>
                          <Select
                            value={formData.tax_included ? 'inclusive' : 'exclusive'}
                            onValueChange={(v) => setFormData({ ...formData, tax_included: v === 'inclusive' })}
                          >
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                              <SelectItem value="exclusive">Tax exclusive</SelectItem>
                              <SelectItem value="inclusive">Tax inclusive</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                        <div className="space-y-2">
                          <Label>Custom Tax Rate (%)</Label>
                          <Input
                            type="number"
                            step="0.01"
                            value={formData.tax_rate_override}
                            onChange={(e) => setFormData({ ...formData, tax_rate_override: e.target.value })}
                            placeholder="e.g. 9"
                          />
                        </div>
                      </div>
                    ) : null}

                    <div className="grid grid-cols-3 gap-4">
                      <div className="space-y-2"><Label>Net</Label><Input readOnly value={formatCurrency(pricingPreview.net)} /></div>
                      <div className="space-y-2"><Label>Tax</Label><Input readOnly value={formatCurrency(pricingPreview.tax)} /></div>
                      <div className="space-y-2"><Label>Gross</Label><Input readOnly value={formatCurrency(pricingPreview.gross)} /></div>
                    </div>
                  </div>
                </div>

                <Separator />

                <div ref={detailTabsRef}>
                <Tabs value={detailsTab} onValueChange={setDetailsTab} className="w-full">
                  <TabsList className="grid w-full grid-cols-4">
                    <TabsTrigger value="inventory">Inventory</TabsTrigger>
                    <TabsTrigger value="attributes" disabled={hasVariants}>Attributes</TabsTrigger>
                    <TabsTrigger value="features">Features</TabsTrigger>
                    <TabsTrigger value="custom">Custom</TabsTrigger>
                  </TabsList>

                  <TabsContent value="inventory" className="pt-4">
                    <div className="space-y-5 rounded-lg border p-4">
                      <div>
                        <div className="mb-1 inline-flex w-fit rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-primary">Tab 3</div>
                        <h2 className="text-2xl font-semibold">Inventory</h2>
                      </div>
                      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                          <Label>Product Status</Label>
                          <Select
                            value={formData.status}
                            onValueChange={(v) => setFormData({ ...formData, status: v as ProductStatus })}
                          >
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="draft">Draft</SelectItem>
                              <SelectItem value="active">Active</SelectItem>
                            </SelectContent>
                          </Select>
                          <p className="text-xs text-muted-foreground">
                            Draft products are not visible in POS or sales.
                          </p>
                        </div>

                        <div className="space-y-2">
                          <Label>Tracking Method</Label>
                          <Select
                            value={formData.tracking_method}
                            onValueChange={(v) => setFormData({ ...formData, tracking_method: v as TrackingMethod })}
                          >
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="none">No Tracking</SelectItem>
                              <SelectItem value="quantity">Quantity Only</SelectItem>
                              <SelectItem value="batch">Batch/Lot Tracking</SelectItem>
                              <SelectItem value="serial">Serial Number Tracking</SelectItem>
                            </SelectContent>
                          </Select>
                          <p className="text-xs text-muted-foreground">
                            {formData.tracking_method === 'batch' && 'Track products by batch/lot with expiry dates.'}
                            {formData.tracking_method === 'serial' && 'Track individual units with unique serial numbers.'}
                            {formData.tracking_method === 'quantity' && 'Track total quantity without individual identification.'}
                            {formData.tracking_method === 'none' && 'No inventory tracking for this product.'}
                          </p>
                        </div>
                      </div>

                      <Separator />

                      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                          <Label htmlFor="reorder_point">Reorder Point</Label>
                          <Input
                            id="reorder_point"
                            type="number"
                            value={formData.reorder_point}
                            onChange={(e) => setFormData({ ...formData, reorder_point: e.target.value })}
                            placeholder="e.g. 10"
                          />
                          <p className="text-xs text-muted-foreground">
                            Alert when stock falls below this quantity.
                          </p>
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor="reorder_quantity">Reorder Quantity</Label>
                          <Input
                            id="reorder_quantity"
                            type="number"
                            value={formData.reorder_quantity}
                            onChange={(e) => setFormData({ ...formData, reorder_quantity: e.target.value })}
                            placeholder="e.g. 50"
                          />
                          <p className="text-xs text-muted-foreground">
                            Suggested quantity to order when restocking.
                          </p>
                        </div>
                      </div>

                      {formData.tracking_method === 'batch' && (
                        <div className="space-y-2">
                          <Label htmlFor="shelf_life_days">Default Shelf Life (days)</Label>
                          <Input
                            id="shelf_life_days"
                            type="number"
                            min="0"
                            value={formData.shelf_life_days}
                            onChange={(e) => setFormData({ ...formData, shelf_life_days: e.target.value })}
                            placeholder="e.g. 365"
                          />
                          <p className="text-xs text-muted-foreground">
                            Default shelf life used to estimate expiry when receiving stock. Lot numbers and actual expiry dates are captured per batch at stock-in.
                          </p>
                        </div>
                      )}

                      <Separator />

                      <div ref={operationsSectionRef}>
                        <Label className="text-sm font-medium">Physical Attributes</Label>
                        <div className="grid grid-cols-2 gap-4 mt-2 md:grid-cols-4">
                          <div className="space-y-2">
                            <Label htmlFor="weight" className="text-xs">Weight</Label>
                            <div className="flex gap-2">
                              <Input
                                id="weight"
                                type="number"
                                step="0.01"
                                value={formData.weight}
                                onChange={(e) => setFormData({ ...formData, weight: e.target.value })}
                                placeholder="0.00"
                                className="flex-1"
                              />
                              <Select
                                value={formData.weight_unit}
                                onValueChange={(v) => setFormData({ ...formData, weight_unit: v })}
                              >
                                <SelectTrigger className="w-20">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="kg">kg</SelectItem>
                                  <SelectItem value="g">g</SelectItem>
                                  <SelectItem value="lb">lb</SelectItem>
                                  <SelectItem value="oz">oz</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                          </div>

                          <div className="space-y-2">
                            <Label htmlFor="length" className="text-xs">Length</Label>
                            <Input
                              id="length"
                              type="number"
                              step="0.01"
                              value={formData.length}
                              onChange={(e) => setFormData({ ...formData, length: e.target.value })}
                              placeholder="0.00"
                            />
                          </div>

                          <div className="space-y-2">
                            <Label htmlFor="width" className="text-xs">Width</Label>
                            <Input
                              id="width"
                              type="number"
                              step="0.01"
                              value={formData.width}
                              onChange={(e) => setFormData({ ...formData, width: e.target.value })}
                              placeholder="0.00"
                            />
                          </div>

                          <div className="space-y-2">
                            <Label htmlFor="height" className="text-xs">Height</Label>
                            <div className="flex gap-2">
                              <Input
                                id="height"
                                type="number"
                                step="0.01"
                                value={formData.height}
                                onChange={(e) => setFormData({ ...formData, height: e.target.value })}
                                placeholder="0.00"
                                className="flex-1"
                              />
                              <Select
                                value={formData.dimension_unit}
                                onValueChange={(v) => setFormData({ ...formData, dimension_unit: v })}
                              >
                                <SelectTrigger className="w-20">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="cm">cm</SelectItem>
                                  <SelectItem value="m">m</SelectItem>
                                  <SelectItem value="in">in</SelectItem>
                                  <SelectItem value="ft">ft</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </TabsContent>

                  <TabsContent value="attributes" className="pt-4">
                    <div className="rounded-lg border p-3 space-y-3">
                      <div className="text-sm text-muted-foreground">
                        {hasVariants 
                            ? "Attributes are managed in the Variants section above." 
                            : "Select attributes and values for this product."}
                      </div>

                      {!hasVariants ? (
                        <div className="space-y-4">
                          <div className="text-xs text-muted-foreground">
                            Pulled from Product Settings → Attributes.
                          </div>

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
                      ) : (
                        <div className="text-sm text-muted-foreground">Attributes are managed in the Variants section above.</div>
                      )}
                    </div>
                  </TabsContent>

                  <TabsContent value="features" className="pt-4">
                    <div ref={controlSectionRef} className="space-y-4 rounded-lg border p-4">
                      <div>
                        <div className="mb-1 inline-flex w-fit rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-primary">Tab 6</div>
                        <h2 className="text-2xl font-semibold">Control</h2>
                      </div>
                      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                          <Label>Product Status</Label>
                          <Select
                            value={formData.status}
                            onValueChange={(v) => setFormData({ ...formData, status: v as ProductStatus })}
                          >
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="draft">Draft</SelectItem>
                              <SelectItem value="active">Active</SelectItem>
                            </SelectContent>
                          </Select>
                          <p className="text-xs text-muted-foreground">Draft products are not visible in POS or sales.</p>
                        </div>
                        <div className="space-y-2">
                          <Label>Warranty Period</Label>
                          <Input placeholder="Enter value" />
                        </div>
                      </div>

                      <Separator />

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
                </div>

                <Separator />

                <div ref={channelsSectionRef} className="space-y-5 rounded-lg border p-4">
                  <div>
                    <div className="mb-1 inline-flex w-fit rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-primary">Tab 5</div>
                    <h2 className="text-2xl font-semibold">Channels</h2>
                  </div>

                  <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div className="flex items-center gap-2 rounded-lg border p-3">
                      <Checkbox checked disabled />
                      <Label>Available in POS</Label>
                    </div>
                    <div className="flex items-center gap-2 rounded-lg border p-3">
                      <Checkbox
                        checked={formData.show_on_website}
                        onCheckedChange={(checked) =>
                          setFormData({ ...formData, show_on_website: checked === true })
                        }
                      />
                      <Label
                        className="cursor-pointer"
                        onClick={() => setFormData({ ...formData, show_on_website: !formData.show_on_website })}
                      >
                        Show on Website
                      </Label>
                    </div>
                    <div className="flex items-center gap-2 rounded-lg border p-3">
                      <Checkbox checked={!!formData.wholesale_price} disabled />
                      <Label>Wholesale</Label>
                    </div>
                  </div>

                  <div className="space-y-4 border-t pt-5">
                    <h3 className="text-lg font-semibold">E-commerce Controls</h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div className="flex items-start gap-2">
                        <Checkbox
                          checked={formData.is_preorder}
                          onCheckedChange={(checked) =>
                            setFormData({ ...formData, is_preorder: checked === true })
                          }
                        />
                        <div className="space-y-1">
                          <Label
                            className="cursor-pointer"
                            onClick={() => setFormData({ ...formData, is_preorder: !formData.is_preorder })}
                          >
                            Enable Pre-order
                          </Label>
                          <div className="text-xs text-muted-foreground">Allows customers to buy even when stock is low/out.</div>
                        </div>
                      </div>

                      <div className="flex items-center gap-2">
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

                      <div className="flex items-start gap-2">
                        <Checkbox
                          checked={formData.is_merchandise}
                          onCheckedChange={(checked) =>
                            setFormData({ ...formData, is_merchandise: checked === true })
                          }
                        />
                        <div className="space-y-1">
                          <Label
                            className="cursor-pointer"
                            onClick={() => setFormData({ ...formData, is_merchandise: !formData.is_merchandise })}
                          >
                            Include in Merchandise View
                          </Label>
                          <div className="text-xs text-muted-foreground">Products marked here appear in the storefront merchandise category.</div>
                        </div>
                      </div>

                      <div className="space-y-2">
                        <Label>Expected Availability Date</Label>
                        <Input
                          type="date"
                          value={formData.preorder_available_at}
                          onChange={(e) => setFormData({ ...formData, preorder_available_at: e.target.value })}
                        />
                      </div>
                    </div>
                  </div>

                  <div className="rounded-lg border border-dashed bg-muted/20 p-4">
                    <div className="mb-3 font-semibold text-primary">Channel Pricing</div>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                      <div className="space-y-2">
                        <Label>POS Price</Label>
                        <Input
                          type="number"
                          step="0.01"
                          placeholder="0.00"
                          value={formData.price}
                          onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label>Online Price</Label>
                        <Input
                          type="number"
                          step="0.01"
                          placeholder="0.00"
                          value={formData.msrp}
                          onChange={(e) => setFormData({ ...formData, msrp: e.target.value })}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label>Wholesale Price</Label>
                        <Input
                          type="number"
                          step="0.01"
                          placeholder="0.00"
                          value={formData.wholesale_price}
                          onChange={(e) => setFormData({ ...formData, wholesale_price: e.target.value })}
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </form>
            </CardContent>
          </Card>
          </div>
        </div>

        <div ref={mediaSectionRef} className="space-y-6 self-start">
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
                onPickFiles={(files: File[]) => handlePickMainImage(files[0] ?? null)}
              />

              {formData.image ? (
                <div className="rounded-md border p-2">
                  <img src={resolveImageUrl(formData.image)} alt="Main" className="h-40 w-full object-cover rounded" />
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
                      title="Click to remove"
                    >
                      <img src={url} alt="Gallery" className="h-20 w-full object-cover rounded" />
                    </button>
                  ))}
                </div>
              ) : null}
            </CardContent>
          </Card>

          <Card id="import">
            <CardHeader>
              <CardTitle>Import products</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="text-sm text-muted-foreground">
                Upload a CSV to bulk-create products.
              </div>
              <Input
                type="file"
                accept=".csv,text/csv"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  if (file) importMutation.mutate(file)
                  e.currentTarget.value = ''
                }}
                disabled={importMutation.isPending}
              />
              <div className="text-xs text-muted-foreground">
                Required columns: <span className="font-medium">name</span>, <span className="font-medium">cost</span>, <span className="font-medium">price</span>. Optional: sku, barcode, type, category_id, stock_quantity.
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      <Dialog
        open={barcodeScannerOpen}
        onOpenChange={(open) => {
          setBarcodeScannerOpen(open)
          if (!open) {
            stopBarcodeScanner()
          }
        }}
      >
        <DialogContent className="sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>Scan Product Barcode</DialogTitle>
            <DialogDescription>
              Point your camera at a barcode. The value will be filled automatically.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="overflow-hidden rounded-md border bg-black">
              <video
                ref={barcodeVideoRef}
                className="h-72 w-full object-cover"
                muted
                playsInline
                autoPlay
              />
            </div>

            {barcodeScannerLoading && (
              <p className="text-sm text-muted-foreground">Starting camera...</p>
            )}

            {barcodeScannerError && (
              <p className="text-sm text-destructive">{barcodeScannerError}</p>
            )}

            <div className="flex justify-end">
              <Button type="button" variant="outline" onClick={() => setBarcodeScannerOpen(false)}>
                Cancel
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
