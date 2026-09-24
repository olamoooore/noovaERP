import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'
import {
  SettingsLoadingCard,
  SettingsRow,
  SettingsSaveBar,
  SettingsSectionHeader,
  SettingsSectionNav,
  type SettingsSectionDef,
} from '@/components/settings/settings-ui'

type SocialLinks = {
  facebook: string
  instagram: string
  x: string
  linkedin: string
  tiktok: string
  youtube: string
  whatsapp: string
}

const EMPTY_SOCIAL_LINKS: SocialLinks = {
  facebook: '',
  instagram: '',
  x: '',
  linkedin: '',
  tiktok: '',
  youtube: '',
  whatsapp: '',
}

function toStringArray(value: unknown, fallback?: string): string[] {
  if (Array.isArray(value)) {
    const cleaned = value
      .map((item) => String(item || '').trim())
      .filter((item) => item.length > 0)
    return cleaned.length > 0 ? cleaned : ['']
  }

  if (typeof value === 'string' && value.trim()) {
    try {
      const parsed = JSON.parse(value)
      if (Array.isArray(parsed)) {
        const cleaned = parsed
          .map((item) => String(item || '').trim())
          .filter((item) => item.length > 0)
        return cleaned.length > 0 ? cleaned : ['']
      }
    } catch {
      return [value.trim()]
    }
  }

  if (fallback && fallback.trim()) {
    return [fallback.trim()]
  }

  return ['']
}

function toSocialLinks(value: unknown): SocialLinks {
  if (typeof value === 'object' && value !== null) {
    const obj = value as Record<string, unknown>
    return {
      facebook: String(obj.facebook || ''),
      instagram: String(obj.instagram || ''),
      x: String(obj.x || ''),
      linkedin: String(obj.linkedin || ''),
      tiktok: String(obj.tiktok || ''),
      youtube: String(obj.youtube || ''),
      whatsapp: String(obj.whatsapp || ''),
    }
  }

  if (typeof value === 'string' && value.trim()) {
    try {
      return toSocialLinks(JSON.parse(value))
    } catch {
      return { ...EMPTY_SOCIAL_LINKS }
    }
  }

  return { ...EMPTY_SOCIAL_LINKS }
}

export function GeneralSettings() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const { tenantId } = useAuthStore()

  const [activeSection, setActiveSection] = useState('profile')
  const [companyName, setCompanyName] = useState('')
  const [logoUrl, setLogoUrl] = useState('')
  const [website, setWebsite] = useState('')
  const [gstRegistrationNumber, setGstRegistrationNumber] = useState('')
  const [phoneNumbers, setPhoneNumbers] = useState<string[]>([''])
  const [addresses, setAddresses] = useState<string[]>([''])
  const [socialLinks, setSocialLinks] = useState<SocialLinks>({ ...EMPTY_SOCIAL_LINKS })

  const { data, isLoading } = useQuery({
    queryKey: ['general-settings'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings')
      return response.data.data
    },
    retry: false,
  })

  useEffect(() => {
    if (!data) {
      return
    }

    setCompanyName(data.company_name || '')
    setLogoUrl(data.company_logo_url || '')
    setWebsite(data.website || '')
    setGstRegistrationNumber(data.gst_registration_number || data.tax_id || '')
    setPhoneNumbers(toStringArray(data.phone_numbers, data.phone))
    setAddresses(toStringArray(data.addresses, data.address))
    setSocialLinks(toSocialLinks(data.social_links))
  }, [data])

  const mutation = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => {
      const response = await api.put('/accounting/settings', payload)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['general-settings'] })
      queryClient.invalidateQueries({ queryKey: ['company-settings'] })
      toast({
        title: 'Settings saved',
        description: 'General settings have been updated.',
      })
    },
    onError: () => {
      toast({
        title: 'Save failed',
        description: 'Could not update general settings.',
        variant: 'destructive',
      })
    },
  })

  const cleanedPhoneNumbers = useMemo(
    () => phoneNumbers.map((value) => value.trim()).filter((value) => value.length > 0),
    [phoneNumbers],
  )

  const cleanedAddresses = useMemo(
    () => addresses.map((value) => value.trim()).filter((value) => value.length > 0),
    [addresses],
  )

  const handlePhoneChange = (index: number, value: string) => {
    setPhoneNumbers((prev) => prev.map((item, i) => (i === index ? value : item)))
  }

  const addPhoneField = () => {
    setPhoneNumbers((prev) => [...prev, ''])
  }

  const removePhoneField = (index: number) => {
    setPhoneNumbers((prev) => {
      const next = prev.filter((_, i) => i !== index)
      return next.length > 0 ? next : ['']
    })
  }

  const handleAddressChange = (index: number, value: string) => {
    setAddresses((prev) => prev.map((item, i) => (i === index ? value : item)))
  }

  const addAddressField = () => {
    setAddresses((prev) => [...prev, ''])
  }

  const removeAddressField = (index: number) => {
    setAddresses((prev) => {
      const next = prev.filter((_, i) => i !== index)
      return next.length > 0 ? next : ['']
    })
  }

  const handleSave = () => {
    const cleanedSocialLinks = Object.fromEntries(
      Object.entries(socialLinks).filter(([, value]) => value.trim().length > 0),
    )

    mutation.mutate({
      company_name: companyName.trim() || null,
      company_logo_url: logoUrl.trim() || null,
      website: website.trim() || null,
      gst_registration_number: gstRegistrationNumber.trim() || null,
      tax_id: gstRegistrationNumber.trim() || null,
      phone: cleanedPhoneNumbers[0] || null,
      phone_numbers: cleanedPhoneNumbers,
      address: cleanedAddresses[0] || null,
      addresses: cleanedAddresses,
      social_links: cleanedSocialLinks,
    })
  }

  const copyTenantId = () => {
    if (tenantId) {
      navigator.clipboard.writeText(tenantId)
      toast({ title: 'Copied', description: 'Tenant ID copied to clipboard.' })
    }
  }

  const save = {
    hasChanges: true,
    isPending: mutation.isPending,
    onSave: handleSave,
    onCancel: () => undefined,
  }

  // ─── Sections ─────────────────────────────────────────────────────────────

  const ProfileSection = () => (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:buildings-2-bold-duotone"
        title="Company Profile"
        description="The legal name, identifier and logo used across documents and reports."
      />
      <CardContent className="space-y-5 py-6">
        <div className="space-y-2">
          <Label className="text-sm font-medium">Company Name</Label>
          <Input
            value={companyName}
            onChange={(e) => setCompanyName(e.target.value)}
            placeholder="My Company"
          />
          <p className="text-xs text-muted-foreground">Appears on invoices, receipts and reports.</p>
        </div>

        <div className="space-y-2">
          <Label className="text-sm font-medium">Tenant ID</Label>
          <div className="flex items-center gap-2">
            <Input value={tenantId || ''} readOnly className="bg-muted font-mono text-sm" />
            <Button type="button" variant="outline" size="icon" onClick={copyTenantId} disabled={!tenantId}>
              <Icon icon="solar:copy-bold-duotone" className="h-4 w-4" />
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Unique identifier for your workspace — share with support when needed.
          </p>
        </div>

        <div className="space-y-2">
          <Label className="text-sm font-medium">Company Logo</Label>
          <div className="flex items-center gap-3">
            <Input
              value={logoUrl}
              onChange={(e) => setLogoUrl(e.target.value)}
              placeholder="https://example.com/logo.png"
              className="flex-1"
            />
            <label className="flex shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 transition-colors hover:bg-muted">
              <Icon icon="solar:upload-bold-duotone" className="h-4 w-4" />
              <span className="text-sm">Upload</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                className="hidden"
                onChange={async (e) => {
                  const file = e.target.files?.[0]
                  if (!file) return
                  const fd = new FormData()
                  fd.append('file', file)
                  fd.append('folder', 'logos')
                  try {
                    const res = await api.post('/upload', fd, {
                      headers: { 'Content-Type': 'multipart/form-data' },
                    })
                    setLogoUrl(res.data.url)
                    toast({ title: 'Uploaded', description: 'Logo uploaded successfully' })
                  } catch {
                    toast({ title: 'Error', description: 'Failed to upload logo', variant: 'destructive' })
                  }
                }}
              />
            </label>
            {logoUrl && (
              <img
                src={logoUrl}
                alt="Logo preview"
                className="h-10 w-10 rounded border object-contain"
                onError={(e) => {
                  (e.target as HTMLImageElement).style.display = 'none'
                }}
              />
            )}
          </div>
          <p className="text-xs text-muted-foreground">
            Enter a public URL or upload an image file. Used on invoices, quotations and other documents.
          </p>
        </div>
      </CardContent>
      <SettingsSaveBar {...save} label="Save company profile" />
    </Card>
  )

  const ContactSection = () => (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:phone-calling-bold-duotone"
        title="Contact Details"
        description="Phone numbers and addresses printed on customer-facing documents."
      />
      <CardContent className="space-y-6 py-6">
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-sm font-medium">Company Phone Numbers</Label>
              <p className="text-xs text-muted-foreground">The first number is used as the primary contact.</p>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={addPhoneField}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-1 h-4 w-4" />
              Add Phone
            </Button>
          </div>
          <div className="space-y-2">
            {phoneNumbers.map((phone, index) => (
              <div key={`phone-${index}`} className="flex items-center gap-2">
                <Input
                  value={phone}
                  onChange={(e) => handlePhoneChange(index, e.target.value)}
                  placeholder="+1 (555) 123-4567"
                />
                {index === 0 && (
                  <span className="shrink-0 rounded bg-muted px-2 py-1 text-[10px] font-medium uppercase text-muted-foreground">
                    Primary
                  </span>
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  onClick={() => removePhoneField(index)}
                  disabled={phoneNumbers.length === 1}
                >
                  <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        </div>

        <div className="space-y-3 border-t pt-6">
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-sm font-medium">Company Addresses</Label>
              <p className="text-xs text-muted-foreground">The first address is used as the primary billing address.</p>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={addAddressField}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-1 h-4 w-4" />
              Add Address
            </Button>
          </div>
          <div className="space-y-2">
            {addresses.map((address, index) => (
              <div key={`address-${index}`} className="flex items-center gap-2">
                <Input
                  value={address}
                  onChange={(e) => handleAddressChange(index, e.target.value)}
                  placeholder="Company address"
                />
                {index === 0 && (
                  <span className="shrink-0 rounded bg-muted px-2 py-1 text-[10px] font-medium uppercase text-muted-foreground">
                    Primary
                  </span>
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  onClick={() => removeAddressField(index)}
                  disabled={addresses.length === 1}
                >
                  <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        </div>
      </CardContent>
      <SettingsSaveBar {...save} label="Save contact details" />
    </Card>
  )

  const OnlineSection = () => (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:global-bold-duotone"
        title="Online Presence"
        description="Your website and social profiles, shown on receipts and customer portals."
      />
      <CardContent className="space-y-5 py-6">
        <div className="space-y-2">
          <Label className="text-sm font-medium">Online Shop / Website</Label>
          <Input
            value={website}
            onChange={(e) => setWebsite(e.target.value)}
            placeholder="https://www.yourstore.com"
          />
          <p className="text-xs text-muted-foreground">Appears on receipts as "Online Shop: …".</p>
        </div>

        <div className="space-y-3 border-t pt-6">
          <div>
            <Label className="text-sm font-medium">Social Media Links</Label>
            <p className="text-xs text-muted-foreground">Leave blank to hide a network.</p>
          </div>
          <div className="grid gap-3 md:grid-cols-2">
            <Input
              value={socialLinks.facebook}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, facebook: e.target.value }))}
              placeholder="Facebook URL"
            />
            <Input
              value={socialLinks.instagram}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, instagram: e.target.value }))}
              placeholder="Instagram URL"
            />
            <Input
              value={socialLinks.x}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, x: e.target.value }))}
              placeholder="X URL"
            />
            <Input
              value={socialLinks.linkedin}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, linkedin: e.target.value }))}
              placeholder="LinkedIn URL"
            />
            <Input
              value={socialLinks.tiktok}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, tiktok: e.target.value }))}
              placeholder="TikTok URL"
            />
            <Input
              value={socialLinks.youtube}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, youtube: e.target.value }))}
              placeholder="YouTube URL"
            />
            <Input
              value={socialLinks.whatsapp}
              onChange={(e) => setSocialLinks((prev) => ({ ...prev, whatsapp: e.target.value }))}
              placeholder="WhatsApp URL"
            />
          </div>
        </div>
      </CardContent>
      <SettingsSaveBar {...save} label="Save online presence" />
    </Card>
  )

  const TaxSection = () => (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:document-text-bold-duotone"
        title="Tax & Registration"
        description="Registration numbers that must appear on tax documents."
      />
      <CardContent className="divide-y py-0">
        <div className="space-y-2 py-4">
          <Label className="text-sm font-medium">GST / Tax Registration Number</Label>
          <Input
            value={gstRegistrationNumber}
            onChange={(e) => setGstRegistrationNumber(e.target.value)}
            placeholder="GST-123456789"
          />
          <p className="text-xs text-muted-foreground">
            Printed on invoices where a tax registration number is required.
          </p>
        </div>
        <SettingsRow
          title="Tax engine"
          description="Rates, treatments and price basis are managed under Sales Settings → Tax & Pricing."
        >
          <Button variant="outline" size="sm" asChild>
            <Link to="/sales/settings">
              Open tax settings
              <Icon icon="solar:alt-arrow-right-bold-duotone" className="ml-1 h-3.5 w-3.5" />
            </Link>
          </Button>
        </SettingsRow>
      </CardContent>
      <SettingsSaveBar {...save} label="Save tax details" />
    </Card>
  )

  const sections: SettingsSectionDef[] = [
    {
      key: 'profile',
      title: 'Company Profile',
      description: 'Legal name, tenant ID and logo',
      icon: 'solar:buildings-2-bold-duotone',
      component: ProfileSection,
    },
    {
      key: 'contact',
      title: 'Contact Details',
      description: 'Phone numbers and addresses',
      icon: 'solar:phone-calling-bold-duotone',
      component: ContactSection,
    },
    {
      key: 'online',
      title: 'Online Presence',
      description: 'Website and social profiles',
      icon: 'solar:global-bold-duotone',
      component: OnlineSection,
    },
    {
      key: 'tax',
      title: 'Tax & Registration',
      description: 'GST / tax registration number',
      icon: 'solar:document-text-bold-duotone',
      component: TaxSection,
    },
  ]

  const ActiveSection = sections.find((s) => s.key === activeSection)?.component ?? sections[0].component

  if (isLoading) {
    return <SettingsLoadingCard />
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-2xl font-bold tracking-tight">General Settings</h3>
        <p className="mt-1 text-sm text-muted-foreground">
          Configure your company details, contact information and branding.
        </p>
      </div>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <SettingsSectionNav
          sections={sections}
          activeKey={activeSection}
          onSelect={setActiveSection}
        />
        <div className="min-w-0 flex-1">
          <ActiveSection />
        </div>
      </div>
    </div>
  )
}
