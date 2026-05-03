import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'
import { Separator } from '@/components/ui/separator'
import { ReceiptPreview } from '@/components/pos/ReceiptPreview'

interface ReceiptSettings {
  store_name: string
  store_address: string
  store_phone: string
  footer_text: string
  logo_url: string | null
  show_logo: boolean
  // Layout options
  paper_width: '58mm' | '80mm'
  font_size: 'small' | 'medium' | 'large'
  line_spacing: 'compact' | 'normal' | 'relaxed'
  header_alignment: 'left' | 'center' | 'right'
  footer_alignment: 'left' | 'center' | 'right'
  // Display options
  show_receipt_number: boolean
  show_date: boolean
  show_cashier: boolean
  show_tax_breakdown: boolean
  show_qr_code: boolean
  qr_code_content: 'receipt_url' | 'store_website' | 'custom'
  qr_code_custom_url?: string
}

export function ReceiptSettingsPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [storeName, setStoreName] = useState('')
  const [storeAddress, setStoreAddress] = useState('')
  const [storePhone, setStorePhone] = useState('')
  const [footerText, setFooterText] = useState('')
  const [showLogo, setShowLogo] = useState(false)
  const [logoFile, setLogoFile] = useState<File | null>(null)
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  
  // Layout options
  const [paperWidth, setPaperWidth] = useState<'58mm' | '80mm'>('80mm')
  const [fontSize, setFontSize] = useState<'small' | 'medium' | 'large'>('medium')
  const [lineSpacing, setLineSpacing] = useState<'compact' | 'normal' | 'relaxed'>('normal')
  const [headerAlignment, setHeaderAlignment] = useState<'left' | 'center' | 'right'>('center')
  const [footerAlignment, setFooterAlignment] = useState<'left' | 'center' | 'right'>('center')
  
  // Display options
  const [showReceiptNumber, setShowReceiptNumber] = useState(true)
  const [showDate, setShowDate] = useState(true)
  const [showCashier, setShowCashier] = useState(true)
  const [showTaxBreakdown, setShowTaxBreakdown] = useState(true)
  const [showQrCode, setShowQrCode] = useState(false)
  const [qrCodeContent, setQrCodeContent] = useState<'receipt_url' | 'store_website' | 'custom'>('receipt_url')
  const [qrCodeCustomUrl, setQrCodeCustomUrl] = useState('')

  // Fetch settings
  const { data: settings, isLoading } = useQuery({
    queryKey: ['receipt-settings'],
    queryFn: async () => {
      const response = await api.get('/pos/receipt-settings')
      return response.data.data as ReceiptSettings
    },
  })

  // Update form when settings load
  useEffect(() => {
    if (settings) {
      setStoreName(settings.store_name || '')
      setStoreAddress(settings.store_address || '')
      setStorePhone(settings.store_phone || '')
      setFooterText(settings.footer_text || 'Thank you for shopping with us!')
      setShowLogo(settings.show_logo || false)
      setLogoPreview(settings.logo_url)
      
      // Layout options
      setPaperWidth(settings.paper_width || '80mm')
      setFontSize(settings.font_size || 'medium')
      setLineSpacing(settings.line_spacing || 'normal')
      setHeaderAlignment(settings.header_alignment || 'center')
      setFooterAlignment(settings.footer_alignment || 'center')
      
      // Display options
      setShowReceiptNumber(settings.show_receipt_number ?? true)
      setShowDate(settings.show_date ?? true)
      setShowCashier(settings.show_cashier ?? true)
      setShowTaxBreakdown(settings.show_tax_breakdown ?? true)
      setShowQrCode(settings.show_qr_code || false)
      setQrCodeContent(settings.qr_code_content || 'receipt_url')
      setQrCodeCustomUrl(settings.qr_code_custom_url || '')
    }
  }, [settings])

  // Update settings mutation
  const updateMutation = useMutation({
    mutationFn: async (data: Partial<ReceiptSettings>) => {
      const response = await api.put('/pos/receipt-settings', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['receipt-settings'] })
      toast({
        title: 'Settings Saved',
        description: 'Receipt settings updated successfully',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update settings',
        variant: 'destructive',
      })
    },
  })

  // Upload logo mutation
  const uploadLogoMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('logo', file)
      const response = await api.post('/pos/receipt-settings/logo', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['receipt-settings'] })
      setLogoPreview(data.data.logo_url)
      setLogoFile(null)
      toast({
        title: 'Logo Uploaded',
        description: 'Receipt logo uploaded successfully',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Upload Failed',
        description: error.response?.data?.message || 'Failed to upload logo',
        variant: 'destructive',
      })
    },
  })

  // Delete logo mutation
  const deleteLogoMutation = useMutation({
    mutationFn: async () => {
      const response = await api.delete('/pos/receipt-settings/logo')
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['receipt-settings'] })
      setLogoPreview(null)
      setLogoFile(null)
      toast({
        title: 'Logo Deleted',
        description: 'Receipt logo removed successfully',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete logo',
        variant: 'destructive',
      })
    },
  })

  const handleSave = () => {
    updateMutation.mutate({
      store_name: storeName,
      store_address: storeAddress,
      store_phone: storePhone,
      footer_text: footerText,
      show_logo: showLogo,
      // Layout options
      paper_width: paperWidth,
      font_size: fontSize,
      line_spacing: lineSpacing,
      header_alignment: headerAlignment,
      footer_alignment: footerAlignment,
      // Display options
      show_receipt_number: showReceiptNumber,
      show_date: showDate,
      show_cashier: showCashier,
      show_tax_breakdown: showTaxBreakdown,
      show_qr_code: showQrCode,
      qr_code_content: qrCodeContent,
      qr_code_custom_url: qrCodeCustomUrl,
    })
  }

  const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) {
      setLogoFile(file)
      const reader = new FileReader()
      reader.onloadend = () => {
        setLogoPreview(reader.result as string)
      }
      reader.readAsDataURL(file)
    }
  }

  const handleUploadLogo = () => {
    if (logoFile) {
      uploadLogoMutation.mutate(logoFile)
    }
  }

  const handleDeleteLogo = () => {
    if (confirm('Are you sure you want to delete the logo?')) {
      deleteLogoMutation.mutate()
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="container max-w-7xl py-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Receipt Settings</h1>
          <p className="text-muted-foreground">
            Customize how your receipts appear when printed
          </p>
        </div>
        <Button
          onClick={() => navigate('/receipt-builder')}
          className="bg-teal-600 hover:bg-teal-700"
        >
          <Icon icon="solar:widget-add-bold-duotone" className="h-5 w-5 mr-2" />
          Open Receipt Builder
        </Button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Settings Forms */}
        <div className="space-y-6">
          <Card>
        <CardHeader>
          <CardTitle>Store Information</CardTitle>
          <CardDescription>
            This information will appear at the top of every receipt
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="storeName">Store Name</Label>
            <Input
              id="storeName"
              placeholder="My Store"
              value={storeName}
              onChange={(e) => setStoreName(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Will appear large and bold at the top of the receipt
            </p>
          </div>

          <div className="space-y-2">
            <Label htmlFor="storeAddress">Store Address</Label>
            <Input
              id="storeAddress"
              placeholder="123 Main Street, City, State 12345"
              value={storeAddress}
              onChange={(e) => setStoreAddress(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="storePhone">Store Phone</Label>
            <Input
              id="storePhone"
              placeholder="(555) 123-4567"
              value={storePhone}
              onChange={(e) => setStorePhone(e.target.value)}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Receipt Logo</CardTitle>
          <CardDescription>
            Add your store logo to receipts (optional)
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center space-x-2">
            <Switch
              id="showLogo"
              checked={showLogo}
              onCheckedChange={setShowLogo}
            />
            <Label htmlFor="showLogo">Show logo on receipts</Label>
          </div>

          {logoPreview && (
            <div className="space-y-2">
              <Label>Current Logo</Label>
              <div className="flex items-center gap-4">
                <div className="w-32 h-32 border rounded-lg overflow-hidden bg-muted flex items-center justify-center">
                  <img
                    src={logoPreview}
                    alt="Receipt logo"
                    className="max-w-full max-h-full object-contain"
                  />
                </div>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleDeleteLogo}
                  disabled={deleteLogoMutation.isPending}
                >
                  <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 mr-2" />
                  Delete Logo
                </Button>
              </div>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="logo">Upload New Logo</Label>
            <div className="flex items-center gap-2">
              <Input
                id="logo"
                type="file"
                accept="image/*"
                onChange={handleLogoChange}
                className="flex-1"
              />
              {logoFile && (
                <Button
                  onClick={handleUploadLogo}
                  disabled={uploadLogoMutation.isPending}
                >
                  {uploadLogoMutation.isPending && (
                    <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                  )}
                  Upload
                </Button>
              )}
            </div>
            <p className="text-xs text-muted-foreground">
              Recommended: Square image, max 2MB (JPEG, PNG, GIF)
            </p>
          </div>

          <div className="rounded-lg bg-amber-50 dark:bg-amber-950 p-4 border border-amber-200 dark:border-amber-800">
            <div className="flex gap-2">
              <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
              <div className="text-sm text-amber-800 dark:text-amber-200">
                <p className="font-medium mb-1">Note about logo printing:</p>
                <p>
                  Logo printing on thermal receipt printers requires special handling. 
                  The logo will be converted to a monochrome bitmap and may not print 
                  on all printer models. For best results, use a simple, high-contrast logo.
                </p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Footer Text</CardTitle>
          <CardDescription>
            Custom message at the bottom of the receipt
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="footerText">Footer Message</Label>
            <Textarea
              id="footerText"
              placeholder="Thank you for shopping with us!"
              value={footerText}
              onChange={(e) => setFooterText(e.target.value)}
              rows={3}
            />
            <p className="text-xs text-muted-foreground">
              This message will appear centered at the bottom of the receipt
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Layout Options</CardTitle>
          <CardDescription>
            Customize the appearance and formatting of your receipts
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="paperWidth">Paper Width</Label>
              <select
                id="paperWidth"
                value={paperWidth}
                onChange={(e) => setPaperWidth(e.target.value as '58mm' | '80mm')}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option value="58mm">58mm (2.25")</option>
                <option value="80mm">80mm (3.15")</option>
              </select>
              <p className="text-xs text-muted-foreground">
                Standard thermal printer paper sizes
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="fontSize">Font Size</Label>
              <select
                id="fontSize"
                value={fontSize}
                onChange={(e) => setFontSize(e.target.value as 'small' | 'medium' | 'large')}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option value="small">Small</option>
                <option value="medium">Medium</option>
                <option value="large">Large</option>
              </select>
              <p className="text-xs text-muted-foreground">
                Text size for receipt content
              </p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="lineSpacing">Line Spacing</Label>
              <select
                id="lineSpacing"
                value={lineSpacing}
                onChange={(e) => setLineSpacing(e.target.value as 'compact' | 'normal' | 'relaxed')}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option value="compact">Compact</option>
                <option value="normal">Normal</option>
                <option value="relaxed">Relaxed</option>
              </select>
              <p className="text-xs text-muted-foreground">
                Space between lines
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="headerAlignment">Header Alignment</Label>
              <select
                id="headerAlignment"
                value={headerAlignment}
                onChange={(e) => setHeaderAlignment(e.target.value as 'left' | 'center' | 'right')}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option value="left">Left</option>
                <option value="center">Center</option>
                <option value="right">Right</option>
              </select>
              <p className="text-xs text-muted-foreground">
                Store info alignment
              </p>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="footerAlignment">Footer Alignment</Label>
            <select
              id="footerAlignment"
              value={footerAlignment}
              onChange={(e) => setFooterAlignment(e.target.value as 'left' | 'center' | 'right')}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <option value="left">Left</option>
              <option value="center">Center</option>
              <option value="right">Right</option>
            </select>
            <p className="text-xs text-muted-foreground">
              Footer message alignment
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Display Options</CardTitle>
          <CardDescription>
            Choose which information to show on receipts
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="showReceiptNumber">Receipt Number</Label>
                <p className="text-xs text-muted-foreground">
                  Show unique receipt identifier
                </p>
              </div>
              <Switch
                id="showReceiptNumber"
                checked={showReceiptNumber}
                onCheckedChange={setShowReceiptNumber}
              />
            </div>

            <Separator />

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="showDate">Date & Time</Label>
                <p className="text-xs text-muted-foreground">
                  Show transaction date and time
                </p>
              </div>
              <Switch
                id="showDate"
                checked={showDate}
                onCheckedChange={setShowDate}
              />
            </div>

            <Separator />

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="showCashier">Cashier Name</Label>
                <p className="text-xs text-muted-foreground">
                  Show who processed the transaction
                </p>
              </div>
              <Switch
                id="showCashier"
                checked={showCashier}
                onCheckedChange={setShowCashier}
              />
            </div>

            <Separator />

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="showTaxBreakdown">Tax Breakdown</Label>
                <p className="text-xs text-muted-foreground">
                  Show detailed tax calculation
                </p>
              </div>
              <Switch
                id="showTaxBreakdown"
                checked={showTaxBreakdown}
                onCheckedChange={setShowTaxBreakdown}
              />
            </div>

            <Separator />

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="showQrCode">QR Code</Label>
                <p className="text-xs text-muted-foreground">
                  Add QR code for digital receipt
                </p>
              </div>
              <Switch
                id="showQrCode"
                checked={showQrCode}
                onCheckedChange={setShowQrCode}
              />
            </div>

            {showQrCode && (
              <div className="ml-6 space-y-3 pt-2">
                <div className="space-y-2">
                  <Label htmlFor="qrCodeContent">QR Code Content</Label>
                  <select
                    id="qrCodeContent"
                    value={qrCodeContent}
                    onChange={(e) => setQrCodeContent(e.target.value as 'receipt_url' | 'store_website' | 'custom')}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <option value="receipt_url">Receipt URL (view online)</option>
                    <option value="store_website">Store Website</option>
                    <option value="custom">Custom URL</option>
                  </select>
                </div>

                {qrCodeContent === 'custom' && (
                  <div className="space-y-2">
                    <Label htmlFor="qrCodeCustomUrl">Custom URL</Label>
                    <Input
                      id="qrCodeCustomUrl"
                      placeholder="https://example.com"
                      value={qrCodeCustomUrl}
                      onChange={(e) => setQrCodeCustomUrl(e.target.value)}
                    />
                  </div>
                )}
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end gap-2">
        <Button
          variant="outline"
          onClick={() => {
            if (settings) {
              setStoreName(settings.store_name || '')
              setStoreAddress(settings.store_address || '')
              setStorePhone(settings.store_phone || '')
              setFooterText(settings.footer_text || 'Thank you for shopping with us!')
              setShowLogo(settings.show_logo || false)
              setPaperWidth(settings.paper_width || '80mm')
              setFontSize(settings.font_size || 'medium')
              setLineSpacing(settings.line_spacing || 'normal')
              setHeaderAlignment(settings.header_alignment || 'center')
              setFooterAlignment(settings.footer_alignment || 'center')
              setShowReceiptNumber(settings.show_receipt_number ?? true)
              setShowDate(settings.show_date ?? true)
              setShowCashier(settings.show_cashier ?? true)
              setShowTaxBreakdown(settings.show_tax_breakdown ?? true)
              setShowQrCode(settings.show_qr_code || false)
              setQrCodeContent(settings.qr_code_content || 'receipt_url')
              setQrCodeCustomUrl(settings.qr_code_custom_url || '')
            }
          }}
        >
          Reset
        </Button>
        <Button
          onClick={handleSave}
          disabled={updateMutation.isPending}
        >
          {updateMutation.isPending && (
            <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
          )}
          Save Settings
        </Button>
      </div>
        </div>

        {/* Live Preview */}
        <div className="lg:sticky lg:top-6 lg:self-start">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:eye-bold-duotone" className="h-5 w-5" />
                Live Preview
              </CardTitle>
              <CardDescription>
                See how your receipt will look with current settings
              </CardDescription>
            </CardHeader>
            <CardContent>
              <ReceiptPreview
                data={{
                  store_name: storeName || 'My Store',
                  store_address: storeAddress || '123 Main St, City, State 12345',
                  store_phone: storePhone || '(555) 123-4567',
                  receipt_number: 'RCP-2024-001',
                  receipt_id: 'RCP-2024-001',
                  date: new Date().toISOString(),
                  cashier: 'John Doe',
                  items: [
                    { name: 'Product A', quantity: 2, price: 10.00, total: 20.00 },
                    { name: 'Product B', quantity: 1, price: 15.50, total: 15.50 },
                    { name: 'Product C', quantity: 3, price: 5.00, total: 15.00 },
                  ],
                  subtotal: 50.50,
                  tax: 4.54,
                  discount: 0,
                  total: 55.04,
                  payment_method: 'Cash',
                  payment_amount: 60.00,
                  change: 4.96,
                  footer_message: footerText || 'Thank you for shopping with us!',
                  // Layout settings
                  paper_width: paperWidth,
                  font_size: fontSize,
                  line_spacing: lineSpacing,
                  header_alignment: headerAlignment,
                  footer_alignment: footerAlignment,
                  // Display settings
                  show_receipt_number: showReceiptNumber,
                  show_date: showDate,
                  show_cashier: showCashier,
                  show_tax_breakdown: showTaxBreakdown,
                  show_qr_code: showQrCode,
                  qr_code_content: qrCodeContent,
                  qr_code_custom_url: qrCodeCustomUrl,
                  store_website: 'https://mystore.com',
                }}
              />
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
