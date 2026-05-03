import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

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
    [phoneNumbers]
  )

  const cleanedAddresses = useMemo(
    () => addresses.map((value) => value.trim()).filter((value) => value.length > 0),
    [addresses]
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
      Object.entries(socialLinks).filter(([, value]) => value.trim().length > 0)
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

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div>
          <h3 className="text-lg font-medium">General Settings</h3>
          <p className="text-sm text-muted-foreground">Loading company details...</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-medium">General Settings</h3>
        <p className="text-sm text-muted-foreground">
          Configure your company details and branding.
        </p>
      </div>

      <div className="grid gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Company Details</CardTitle>
            <CardDescription>
              This information will appear on invoices and reports.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">Company Name</label>
              <Input 
                value={companyName} 
                onChange={(e) => setCompanyName(e.target.value)} 
                placeholder="My Company"
              />
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">Logo URL</label>
              <Input 
                value={logoUrl} 
                onChange={(e) => setLogoUrl(e.target.value)} 
                placeholder="https://example.com/logo.png"
              />
              <p className="text-xs text-muted-foreground">
                Enter a public URL for your logo.
              </p>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Online Shop / Website</label>
              <Input
                value={website}
                onChange={(e) => setWebsite(e.target.value)}
                placeholder="https://www.yourstore.com"
              />
              <p className="text-xs text-muted-foreground">
                Appears on receipts as "Online Shop: ..."
              </p>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">GST Reg Number</label>
              <Input
                value={gstRegistrationNumber}
                onChange={(e) => setGstRegistrationNumber(e.target.value)}
                placeholder="GST-123456789"
              />
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium">Company Phone Numbers</label>
                <Button type="button" variant="outline" size="sm" onClick={addPhoneField}>
                  <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-1" />
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
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      onClick={() => removePhoneField(index)}
                      disabled={phoneNumbers.length === 1}
                    >
                      <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium">Company Addresses</label>
                <Button type="button" variant="outline" size="sm" onClick={addAddressField}>
                  <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-1" />
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
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      onClick={() => removeAddressField(index)}
                      disabled={addresses.length === 1}
                    >
                      <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            <div className="space-y-3">
              <label className="text-sm font-medium">Social Media Links</label>
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
        </Card>

        <div className="flex justify-end">
          <Button onClick={handleSave} disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving...' : 'Save Changes'}
          </Button>
        </div>
      </div>
    </div>
  )
}
