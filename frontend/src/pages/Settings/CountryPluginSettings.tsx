import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import { Icon } from '@iconify/react'

type CountryCode = 'SG' | 'MY'

interface TenantCountryPluginSettingsResponse {
  tenant_id: string
  tenant_country_code: CountryCode | null
  country_plugin_enabled: boolean
}

interface ComplianceConfigResponse {
  country_code: string
  is_enabled: boolean
  test_mode: boolean
  credentials: Record<string, any>
  credentials_complete: boolean
  missing_credentials: string[]
}

export function CountryPluginSettings() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [countryCode, setCountryCode] = useState<CountryCode>('SG')
  const [enabled, setEnabled] = useState(false)

  // MyInvois credential form state (MY only)
  const [myinvoisForm, setMyinvoisForm] = useState({
    myinvoice_client_id: '',
    myinvoice_client_secret: '',
    supplier_tin: '',
    supplier_name: '',
    supplier_id_type: 'BRN',
    supplier_id_number: '',
    supplier_msic_code: '',
    supplier_business_activity_description: '',
    supplier_phone: '',
    supplier_email: '',
  })

  const { data, isLoading } = useQuery<TenantCountryPluginSettingsResponse>({
    queryKey: ['tenant-country-plugin-settings'],
    queryFn: async () => {
      const res = await api.get('/accounting/tenant/country-plugin-settings')
      return res.data
    },
  })

  // Fetch compliance config when MY is selected
  const { data: complianceData } = useQuery<ComplianceConfigResponse>({
    queryKey: ['myinvois-config'],
    queryFn: async () => {
      const res = await api.get('/accounting/compliance/config/MY')
      return res.data?.data
    },
    enabled: countryCode === 'MY',
  })

  useEffect(() => {
    if (!data) return
    if (data.tenant_country_code === 'SG' || data.tenant_country_code === 'MY') {
      setCountryCode(data.tenant_country_code)
    }
    setEnabled(Boolean(data.country_plugin_enabled))
  }, [data])

  useEffect(() => {
    if (complianceData?.credentials) {
      const c = complianceData.credentials
      setMyinvoisForm({
        myinvoice_client_id: c.myinvoice_client_id || '',
        myinvoice_client_secret: '',
        supplier_tin: c.supplier_tin || '',
        supplier_name: c.supplier_name || '',
        supplier_id_type: c.supplier_id_type || 'BRN',
        supplier_id_number: c.supplier_id_number || '',
        supplier_msic_code: c.supplier_msic_code || '',
        supplier_business_activity_description: c.supplier_business_activity_description || '',
        supplier_phone: c.supplier_phone || '',
        supplier_email: c.supplier_email || '',
      })
    }
  }, [complianceData])

  // Save country plugin settings
  const savePluginMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        tenant_country_code: countryCode,
        country_plugin_enabled: enabled,
        seed_country_data: enabled,
      }
      const res = await api.put('/accounting/tenant/country-plugin-settings', payload)
      return res.data
    },
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ['tenant-country-plugin-settings'] })
      const seeded = Boolean(res?.data?.seeded)
      toast({
        title: 'Saved',
        description: seeded
          ? `Country plugin updated. ${countryCode} chart of accounts and tax data seeded.`
          : 'Country plugin settings updated.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Failed',
        description: error?.response?.data?.message || 'Could not update country plugin settings.',
        variant: 'destructive',
      })
    },
  })

  // Save MyInvois credentials
  const saveCredentialsMutation = useMutation({
    mutationFn: async () => {
      const { myinvoice_client_secret: clientSecret, ...nonSecretCredentials } = myinvoisForm
      const credentials = {
        ...nonSecretCredentials,
        ...(clientSecret ? { myinvoice_client_secret: clientSecret } : {}),
      }
      const payload = {
        is_enabled: enabled,
        test_mode: complianceData?.test_mode ?? true,
        provider_name: 'myinvoice',
        credentials,
      }
      const res = await api.put('/accounting/compliance/config/MY', payload)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['myinvois-config'] })
      toast({ title: 'Credentials saved', description: 'MyInvois LHDN credentials updated.' })
    },
    onError: (err: any) => {
      toast({ title: 'Save failed', description: err?.response?.data?.message || 'Unknown error', variant: 'destructive' })
    },
  })

  const showMyInvois = countryCode === 'MY' && enabled
  const credsComplete = complianceData?.credentials_complete
  const missingCreds = complianceData?.missing_credentials || []

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Country Plugin Settings</CardTitle>
          <CardDescription>
            Set the tenant country plugin to Singapore (SG) or Malaysia (MY). When enabled, the system can seed country-specific chart of accounts and tax setup.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="space-y-2 max-w-md">
            <Label htmlFor="tenantCountry">Tenant Country</Label>
            <Select value={countryCode} onValueChange={(value) => setCountryCode(value as CountryCode)}>
              <SelectTrigger id="tenantCountry">
                <SelectValue placeholder="Select tenant country" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="SG">Singapore (SG)</SelectItem>
                <SelectItem value="MY">Malaysia (MY)</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              This controls which country plugin routes and country-specific accounts are allowed for this tenant.
            </p>
          </div>

          <div className="flex items-center justify-between rounded-lg border p-4 max-w-2xl">
            <div className="space-y-0.5">
              <Label htmlFor="countryPluginEnabled" className="text-base font-medium">
                Enable Country Plugin
              </Label>
              <p className="text-sm text-muted-foreground">
                When enabled, only the selected country plugin (SG or MY) is available to this tenant.
              </p>
            </div>
            <Switch
              id="countryPluginEnabled"
              checked={enabled}
              onCheckedChange={setEnabled}
            />
          </div>

          <div className="flex gap-3">
            <Button onClick={() => savePluginMutation.mutate()} disabled={isLoading || savePluginMutation.isPending}>
              {savePluginMutation.isPending ? 'Saving...' : 'Save Country Plugin Settings'}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* MyInvois LHDN Credentials — only visible when MY is selected and enabled */}
      {showMyInvois && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              MyInvois LHDN Credentials
              {credsComplete ? (
                <Badge className="bg-green-100 text-green-700">Complete</Badge>
              ) : (
                <Badge variant="destructive">{missingCreds.length} missing</Badge>
              )}
            </CardTitle>
            <CardDescription>
              Required to submit e-invoices to Malaysia LHDN MyInvois portal.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            {/* API Credentials */}
            <div className="space-y-4">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">LHDN API Credentials</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Client ID</Label>
                  <Input
                    value={myinvoisForm.myinvoice_client_id}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, myinvoice_client_id: e.target.value })}
                    placeholder="LHDN API Client ID"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Client Secret</Label>
                  <Input
                    type="password"
                    value={myinvoisForm.myinvoice_client_secret}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, myinvoice_client_secret: e.target.value })}
                    placeholder={complianceData?.credentials?.myinvoice_client_secret_set ? '•••••••• (set)' : 'Enter client secret'}
                  />
                </div>
              </div>
            </div>

            {/* Supplier Identity */}
            <div className="space-y-4">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">Supplier Identity</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Supplier TIN</Label>
                  <Input
                    value={myinvoisForm.supplier_tin}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_tin: e.target.value })}
                    placeholder="e.g. C23012345678"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Supplier Name</Label>
                  <Input
                    value={myinvoisForm.supplier_name}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_name: e.target.value })}
                    placeholder="Company name as registered with LHDN"
                  />
                </div>
                <div className="space-y-2">
                  <Label>ID Type</Label>
                  <Select value={myinvoisForm.supplier_id_type} onValueChange={(v) => setMyinvoisForm({ ...myinvoisForm, supplier_id_type: v })}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="BRN">Business Registration Number (BRN)</SelectItem>
                      <SelectItem value="NRIC">NRIC</SelectItem>
                      <SelectItem value="PASSPORT">Passport</SelectItem>
                      <SelectItem value="ARMY">Army ID</SelectItem>
                      <SelectItem value="TIN">TIN</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>ID Number</Label>
                  <Input
                    value={myinvoisForm.supplier_id_number}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_id_number: e.target.value })}
                    placeholder="Registration / IC number"
                  />
                </div>
                <div className="space-y-2">
                  <Label>MSIC Code</Label>
                  <Input
                    value={myinvoisForm.supplier_msic_code}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_msic_code: e.target.value })}
                    placeholder="e.g. 62010"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Business Activity</Label>
                  <Input
                    value={myinvoisForm.supplier_business_activity_description}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_business_activity_description: e.target.value })}
                    placeholder="e.g. Software Development"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Phone</Label>
                  <Input
                    value={myinvoisForm.supplier_phone}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_phone: e.target.value })}
                    placeholder="+60123456789"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Email</Label>
                  <Input
                    type="email"
                    value={myinvoisForm.supplier_email}
                    onChange={(e) => setMyinvoisForm({ ...myinvoisForm, supplier_email: e.target.value })}
                    placeholder="company@example.com"
                  />
                </div>
              </div>
            </div>

            <Button onClick={() => saveCredentialsMutation.mutate()} disabled={saveCredentialsMutation.isPending}>
              {saveCredentialsMutation.isPending ? (
                <><Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" /> Saving...</>
              ) : (
                <><Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" /> Save Credentials</>
              )}
            </Button>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
