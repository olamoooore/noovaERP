import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'

type CountryCode = 'SG' | 'MY'

interface TenantCountryPluginSettingsResponse {
  tenant_id: string
  tenant_country_code: CountryCode | null
  country_plugin_enabled: boolean
}

export function CountryPluginSettings() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [countryCode, setCountryCode] = useState<CountryCode>('SG')
  const [enabled, setEnabled] = useState(false)

  const { data, isLoading } = useQuery<TenantCountryPluginSettingsResponse>({
    queryKey: ['tenant-country-plugin-settings'],
    queryFn: async () => {
      const res = await api.get('/accounting/tenant/country-plugin-settings')
      return res.data
    },
  })

  useEffect(() => {
    if (!data) return

    if (data.tenant_country_code === 'SG' || data.tenant_country_code === 'MY') {
      setCountryCode(data.tenant_country_code)
    }
    setEnabled(Boolean(data.country_plugin_enabled))
  }, [data])

  const saveMutation = useMutation({
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
      const message = error?.response?.data?.message || 'Could not update country plugin settings.'
      toast({
        title: 'Failed',
        description: message,
        variant: 'destructive',
      })
    },
  })

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
            <Button onClick={() => saveMutation.mutate()} disabled={isLoading || saveMutation.isPending}>
              {saveMutation.isPending ? 'Saving...' : 'Save Country Plugin Settings'}
            </Button>
          </div>

          <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
            <p>
              Current behavior:
              {' '}
              if plugin is enabled and country is MY, Malaysia CoA/tax data is seeded; if SG, Singapore CoA/tax data is seeded.
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
