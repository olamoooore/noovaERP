import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge'
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

  const [searchParams] = useSearchParams()
  const [activeSection, setActiveSection] = useState<string>(() => searchParams.get('section') ?? 'profile')
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

  // ─── AI provider (BYOK) ──────────────────────────────────────────────────

  const AiSection = () => {
    const [expanded, setExpanded] = useState(false)
    const [provider, setProvider] = useState<'ollama' | 'cerebras'>('ollama')
    const [ollamaModel, setOllamaModel] = useState('')
    const [cerebrasModel, setCerebrasModel] = useState('qwen-3.8-27b')
    const [apiKey, setApiKey] = useState('')
    const [assistantMode, setAssistantMode] = useState<'auto' | 'on' | 'off'>('auto')
    const [assistantName, setAssistantName] = useState('')
    const [assistantIcon, setAssistantIcon] = useState('')
    const [assistantWelcome, setAssistantWelcome] = useState('')
    const iconFileRef = useRef<HTMLInputElement | null>(null)

    const PRESET_ASSISTANT_ICONS = ['🤖', '✨', '💬', '🛍️', '🎧', '🧑‍💼', '📦', '💎', '🧠', '🐾', '🌟', '🛒']

    // Knowledge base (RAG) state
    const [knowledgeFormOpen, setKnowledgeFormOpen] = useState(false)
    const [knowledgeQuestion, setKnowledgeQuestion] = useState('')
    const [knowledgeAnswer, setKnowledgeAnswer] = useState('')
    const [knowledgeCategory, setKnowledgeCategory] = useState('faq')

    const aiSettings = useQuery({
      queryKey: ['ai-provider-settings'],
      queryFn: async () => (await api.get('/ai/settings')).data.data,
    })

    useEffect(() => {
      const data = aiSettings.data
      if (!data) return
      setProvider(data.provider === 'cerebras' ? 'cerebras' : 'ollama')
      setOllamaModel(data.ollama?.model ?? '')
      setCerebrasModel(data.cerebras?.model ?? 'qwen-3.8-27b')
      const assistant = data.assistant
      if (assistant) {
        setAssistantMode(assistant.mode === 'manual' ? (assistant.enabled ? 'on' : 'off') : 'auto')
        setAssistantName(assistant.name ?? '')
        setAssistantIcon(assistant.icon ?? '')
        setAssistantWelcome(assistant.welcome ?? '')
      }
    }, [aiSettings.data])

    const saveAi = useMutation({
      mutationFn: async () =>
        (await api.put('/ai/settings', {
          provider,
          ollama_model: provider === 'ollama' ? ollamaModel || undefined : undefined,
          cerebras_model: provider === 'cerebras' ? cerebrasModel || undefined : undefined,
          cerebras_api_key: apiKey || undefined,
          assistant_enabled: assistantMode === 'auto' ? null : assistantMode === 'on',
          assistant_name: assistantName,
          assistant_icon: assistantIcon,
          assistant_welcome: assistantWelcome,
        })).data,
      onSuccess: () => {
        setApiKey('')
        queryClient.invalidateQueries({ queryKey: ['ai-provider-settings'] })
        queryClient.invalidateQueries({ queryKey: ['ai-status'] })
        toast({ title: 'AI settings saved' })
      },
      onError: (error: any) => toast({
        title: 'Could not save AI settings',
        description: error.response?.data?.message || 'Requires settings.write permission.',
        variant: 'destructive',
      }),
    })

    const uploadIcon = useMutation({
      mutationFn: async (file: File) => {
        const form = new FormData()
        form.append('icon', file)
        return (await api.post('/ai/settings/assistant-icon', form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })).data
      },
      onSuccess: (data: any) => {
        setAssistantIcon(data?.icon_url ?? '')
        queryClient.invalidateQueries({ queryKey: ['ai-provider-settings'] })
        toast({ title: 'Assistant icon uploaded' })
      },
      onError: (error: any) => toast({
        title: 'Upload failed',
        description: error.response?.data?.message || 'Use a PNG/JPG/GIF/WebP under 5 MB.',
        variant: 'destructive',
      }),
    })

    const knowledge = useQuery({
      queryKey: ['ai-knowledge'],
      queryFn: async () => (await api.get('/ai/knowledge', { params: { per_page: 100 } })).data,
    })

    const addKnowledge = useMutation({
      mutationFn: async () => (await api.post('/ai/knowledge', {
        category: knowledgeCategory,
        question: knowledgeQuestion,
        answer: knowledgeAnswer,
      })).data,
      onSuccess: () => {
        setKnowledgeQuestion('')
        setKnowledgeAnswer('')
        queryClient.invalidateQueries({ queryKey: ['ai-knowledge'] })
        toast({ title: 'Knowledge entry added' })
      },
      onError: (error: any) => toast({
        title: 'Could not add entry',
        description: error.response?.data?.message || 'Check the question and answer fields.',
        variant: 'destructive',
      }),
    })

    const deleteKnowledge = useMutation({
      mutationFn: async (id: number) => (await api.delete(`/ai/knowledge/${id}`)).data,
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['ai-knowledge'] })
        toast({ title: 'Knowledge entry deleted' })
      },
    })

    const importKnowledge = useMutation({
      mutationFn: async () => (await api.post('/ai/knowledge/import-defaults')).data,
      onSuccess: (data: any) => {
        queryClient.invalidateQueries({ queryKey: ['ai-knowledge'] })
        toast({ title: `Imported ${data?.imported ?? 0} starter knowledge entries` })
      },
      onError: (error: any) => toast({
        title: 'Import failed',
        description: error.response?.data?.message || 'Requires settings.write permission.',
        variant: 'destructive',
      }),
    })

    const testAi = useMutation({
      mutationFn: async () =>
        (await api.post('/ai/settings/test', {
          provider,
          model: provider === 'cerebras' ? cerebrasModel : ollamaModel,
          cerebras_api_key: apiKey || undefined,
        })).data.data,
      onSuccess: (data: any) => toast({
        title: data?.ok ? 'Connection OK' : 'Connection failed',
        description: data?.ok
          ? `${data.provider} · ${data.model}${data.sample ? ` — “${String(data.sample).trim().slice(0, 40)}”` : ''}`
          : (data?.error || 'The provider did not accept the request.'),
        variant: data?.ok ? 'default' : 'destructive',
      }),
      onError: (error: any) => toast({
        title: 'Connection test failed',
        description: error.response?.data?.message || 'Could not reach the provider.',
        variant: 'destructive',
      }),
    })

    const health = aiSettings.data?.health
    const cerebras = aiSettings.data?.cerebras
    const assistant = aiSettings.data?.assistant

    return (
      <Card className="overflow-hidden">
        <SettingsSectionHeader
          icon="solar:cpu-bolt-bold-duotone"
          title="AI Configuration"
          description="Provider, model, website assistant identity and API key for chatbot, tax advisory and AI reviews (BYOK)."
        />
        <CardContent className="space-y-4 py-6">
          {aiSettings.isLoading ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Icon icon="eos-icons:loading" className="h-5 w-5 animate-spin text-primary" />
              Loading AI settings…
            </div>
          ) : (
            <>
              {/* Minimised summary */}
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline" className="font-normal">
                  {aiSettings.data?.provider === 'cerebras' ? 'Cerebras (cloud)' : 'Ollama (local)'}
                </Badge>
                <Badge variant="outline" className="font-mono text-[11px] font-normal">
                  {aiSettings.data?.provider === 'cerebras' ? cerebras?.model : aiSettings.data?.ollama?.model}
                </Badge>
                <span className={`inline-flex h-6 items-center gap-1.5 rounded-full px-2 text-[11px] font-medium ${health?.ok ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'}`}>
                  <span className={`h-1.5 w-1.5 rounded-full ${health?.ok ? 'bg-emerald-500' : 'bg-rose-500'}`} />
                  {health?.ok ? 'Connected' : 'Unreachable'}
                </span>
                {cerebras?.has_custom_key ? (
                  <Badge variant="secondary" className="bg-muted font-normal text-muted-foreground">Own key {cerebras.key_hint}</Badge>
                ) : cerebras?.using_default_key ? (
                  <Badge variant="secondary" className="font-normal">Workspace default key</Badge>
                ) : (
                  <Badge variant="secondary" className="font-normal">No Cerebras key</Badge>
                )}
                {assistant && (
                  <Badge variant="outline" className="font-normal">
                    <Icon icon="solar:chat-round-dots-bold-duotone" className="mr-1 h-3.5 w-3.5" />
                    {assistant.enabled ? 'Assistant on' : 'Assistant off'}
                    {assistant.name ? ` · ${assistant.name}` : ''}
                  </Badge>
                )}
                <HeaderActionButton
                  variant="outline"
                  icon={expanded ? 'solar:minimize-square-bold-duotone' : 'solar:settings-bold-duotone'}
                  onClick={() => setExpanded((v) => !v)}
                  className="ml-auto"
                >
                  {expanded ? 'Minimise' : 'Configure'}
                </HeaderActionButton>
              </div>

              {expanded && (
                <div className="space-y-4 border-t pt-4">
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                      <Label className="text-sm font-medium">Provider</Label>
                      <Select value={provider} onValueChange={(value) => setProvider(value as 'ollama' | 'cerebras')}>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="ollama">Ollama — local inference</SelectItem>
                          <SelectItem value="cerebras">Cerebras — cloud (fast, BYOK)</SelectItem>
                        </SelectContent>
                      </Select>
                      <p className="text-xs text-muted-foreground">
                        Used by the chatbot, tax advisory and all AI reviews.
                      </p>
                    </div>

                    <div className="space-y-2">
                      <Label className="text-sm font-medium">Model</Label>
                      {provider === 'cerebras' ? (
                        <Select value={cerebrasModel} onValueChange={setCerebrasModel}>
                          <SelectTrigger>
                            <SelectValue placeholder="Select a Cerebras model" />
                          </SelectTrigger>
                          <SelectContent>
                            {(cerebras?.models ?? ['qwen-3.8-27b', 'gpt-oss-120b']).map((model: string) => (
                              <SelectItem key={model} value={model}>{model}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      ) : (
                        <Select value={ollamaModel} onValueChange={setOllamaModel}>
                          <SelectTrigger>
                            <SelectValue placeholder="Select a local model" />
                          </SelectTrigger>
                          <SelectContent>
                            {(aiSettings.data?.ollama?.models ?? []).length > 0 ? (
                              (aiSettings.data?.ollama?.models ?? []).map((model: string) => (
                                <SelectItem key={model} value={model}>{model}</SelectItem>
                              ))
                            ) : (
                              <SelectItem value={ollamaModel || 'llama3.1:8b'}>{ollamaModel || 'llama3.1:8b'}</SelectItem>
                            )}
                          </SelectContent>
                        </Select>
                      )}
                    </div>
                  </div>

                  {provider === 'cerebras' && (
                    <div className="space-y-2">
                      <Label className="text-sm font-medium">Cerebras API key (optional — BYOK)</Label>
                      <Input
                        type="password"
                        value={apiKey}
                        onChange={(e) => setApiKey(e.target.value)}
                        placeholder={cerebras?.has_custom_key ? 'Leave blank to keep your saved key' : 'csk-… leave blank to use the workspace default key'}
                        autoComplete="off"
                      />
                      <p className="text-xs text-muted-foreground">
                        Bring your own key from cerebras.ai; leave blank to use the workspace default
                        {cerebras?.using_default_key ? ' (currently active)' : ''}.
                      </p>
                    </div>
                  )}

                  {/* Website AI Assistant identity */}
                  <div className="space-y-4 border-t pt-4">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:chat-round-dots-bold-duotone" className="h-4 w-4 text-primary" />
                      <p className="text-sm font-medium">Website AI Assistant</p>
                      <p className="text-xs text-muted-foreground">— shown as the chat bubble on your shop/website</p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <div className="space-y-2">
                        <Label className="text-sm font-medium">Availability</Label>
                        <Select value={assistantMode} onValueChange={(value) => setAssistantMode(value as 'auto' | 'on' | 'off')}>
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="auto">Auto — while shop + AI are enabled</SelectItem>
                            <SelectItem value="on">Always on</SelectItem>
                            <SelectItem value="off">Off</SelectItem>
                          </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                          {assistant?.mode === 'auto'
                            ? `Auto currently resolves to ${assistant.enabled ? 'on' : 'off'}.`
                            : 'Pinned manually — ignores module changes.'}
                        </p>
                      </div>

                      <div className="space-y-2">
                        <Label className="text-sm font-medium">Bot name</Label>
                        <Input
                          value={assistantName}
                          onChange={(e) => setAssistantName(e.target.value)}
                          placeholder="NoovaERP AI Assistant"
                          maxLength={60}
                        />
                      </div>

                    </div>

                    <div className="space-y-2">
                      <Label className="text-sm font-medium">Bot icon / image</Label>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border bg-muted">
                          {/^https?:\/\//i.test(assistantIcon) ? (
                            <img src={assistantIcon} alt="bot icon" className="h-full w-full object-cover" />
                          ) : (
                            <span className="text-lg leading-none">{assistantIcon || '🤖'}</span>
                          )}
                        </span>
                        <Input
                          value={assistantIcon}
                          onChange={(e) => setAssistantIcon(e.target.value)}
                          placeholder="🤖 or https://…/bot.png"
                          maxLength={512}
                          className="max-w-xs"
                        />
                        <HeaderActionButton
                          variant="outline"
                          icon="solar:upload-bold-duotone"
                          loading={uploadIcon.isPending}
                          disabled={uploadIcon.isPending}
                          onClick={() => iconFileRef.current?.click()}
                        >
                          Upload image
                        </HeaderActionButton>
                        <input
                          ref={iconFileRef}
                          type="file"
                          accept="image/png,image/jpeg,image/gif,image/webp"
                          className="hidden"
                          onChange={(e) => {
                            const file = e.target.files?.[0]
                            if (file) uploadIcon.mutate(file)
                            e.target.value = ''
                          }}
                        />
                      </div>
                      <div className="flex flex-wrap items-center gap-1.5">
                        {PRESET_ASSISTANT_ICONS.map((emoji) => (
                          <button
                            key={emoji}
                            type="button"
                            onClick={() => setAssistantIcon(emoji)}
                            className={`flex h-8 w-8 items-center justify-center rounded-lg border text-base transition-colors hover:border-primary/50 ${
                              assistantIcon === emoji ? 'border-primary bg-primary/10' : 'bg-card'
                            }`}
                            aria-label={`Use ${emoji} as assistant icon`}
                          >
                            {emoji}
                          </button>
                        ))}
                      </div>
                      <p className="text-xs text-muted-foreground">
                        Choose a preset icon, upload a PNG/JPG/GIF/WebP, or paste an emoji / image URL.
                        Uploads save immediately.
                      </p>
                    </div>

                    <div className="space-y-2">
                      <Label className="text-sm font-medium">Welcome message</Label>
                      <textarea
                        value={assistantWelcome}
                        onChange={(e) => setAssistantWelcome(e.target.value)}
                        placeholder="👋 Hi! How can I help you today?"
                        rows={2}
                        maxLength={300}
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                      />
                      <p className="text-xs text-muted-foreground">Shown when a shopper opens the assistant.</p>
                    </div>
                  </div>

                  {/* Knowledge base (RAG) */}
                  <div className="space-y-3 border-t pt-4">
                    <div className="flex flex-wrap items-center gap-2">
                      <Icon icon="solar:notebook-bookmark-bold-duotone" className="h-4 w-4 text-primary" />
                      <p className="text-sm font-medium">Knowledge base</p>
                      <p className="text-xs text-muted-foreground">— official answers the assistant quotes (shipping, returns, membership, FAQs)</p>
                      <span className="ml-auto text-xs text-muted-foreground">
                        {knowledge.data?.meta?.total ?? 0} entries
                      </span>
                    </div>

                    <div className="flex flex-wrap gap-2">
                      <HeaderActionButton
                        variant="outline"
                        icon="solar:download-minimalistic-bold-duotone"
                        loading={importKnowledge.isPending}
                        disabled={importKnowledge.isPending}
                        onClick={() => importKnowledge.mutate()}
                      >
                        Import starter knowledge
                      </HeaderActionButton>
                      <HeaderActionButton
                        variant="outline"
                        icon="solar:add-circle-bold-duotone"
                        onClick={() => setKnowledgeFormOpen((prev) => !prev)}
                      >
                        {knowledgeFormOpen ? 'Close form' : 'Add entry'}
                      </HeaderActionButton>
                    </div>

                    {knowledgeFormOpen && (
                      <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
                        <div className="grid gap-2 sm:grid-cols-[180px_1fr]">
                          <Select value={knowledgeCategory} onValueChange={setKnowledgeCategory}>
                            <SelectTrigger>
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {['faq', 'shipping', 'orders', 'returns', 'payments', 'membership', 'products', 'support', 'policy', 'other'].map((category) => (
                                <SelectItem key={category} value={category}>{category}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <Input
                            value={knowledgeQuestion}
                            onChange={(e) => setKnowledgeQuestion(e.target.value)}
                            placeholder="Question — e.g. How much does delivery cost?"
                            maxLength={500}
                          />
                        </div>
                        <textarea
                          value={knowledgeAnswer}
                          onChange={(e) => setKnowledgeAnswer(e.target.value)}
                          placeholder="Answer the assistant should give…"
                          rows={2}
                          maxLength={5000}
                          className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <HeaderActionButton
                          icon="solar:diskette-bold-duotone"
                          loading={addKnowledge.isPending}
                          disabled={addKnowledge.isPending || !knowledgeQuestion.trim() || !knowledgeAnswer.trim()}
                          onClick={() => addKnowledge.mutate()}
                        >
                          Add knowledge entry
                        </HeaderActionButton>
                      </div>
                    )}

                    <div className="max-h-72 space-y-1.5 overflow-y-auto pr-1">
                      {knowledge.isLoading ? (
                        <p className="text-xs text-muted-foreground">Loading knowledge…</p>
                      ) : (knowledge.data?.documents?.length ?? 0) === 0 ? (
                        <p className="text-xs text-muted-foreground">
                          No entries yet — import the starter pack or add your own so the assistant can answer policy questions.
                        </p>
                      ) : (
                        knowledge.data.documents.map((doc: any) => (
                          <div key={doc.id} className="flex items-start gap-2 rounded-lg border bg-card px-3 py-2">
                            <span className="mt-0.5 shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-primary">
                              {doc.category}
                            </span>
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-sm font-medium text-foreground">{doc.question}</p>
                              <p className="truncate text-xs text-muted-foreground">{doc.answer}</p>
                            </div>
                            <button
                              type="button"
                              onClick={() => deleteKnowledge.mutate(doc.id)}
                              className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                              aria-label="Delete knowledge entry"
                            >
                              <Icon icon="solar:trash-bin-trash-linear" className="h-4 w-4" />
                            </button>
                          </div>
                        ))
                      )}
                    </div>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <HeaderActionButton
                      icon="solar:diskette-bold-duotone"
                      loading={saveAi.isPending}
                      disabled={saveAi.isPending}
                      onClick={() => saveAi.mutate()}
                    >
                      Save AI settings
                    </HeaderActionButton>
                    <HeaderActionButton
                      variant="outline"
                      icon="solar:plug-circle-bold-duotone"
                      loading={testAi.isPending}
                      disabled={testAi.isPending}
                      onClick={() => testAi.mutate()}
                    >
                      Test connection
                    </HeaderActionButton>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    )
  }

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
    {
      key: 'ai',
      title: 'AI Configuration',
      description: 'Provider, model, website assistant identity (BYOK)',
      icon: 'solar:cpu-bolt-bold-duotone',
      component: AiSection,
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
