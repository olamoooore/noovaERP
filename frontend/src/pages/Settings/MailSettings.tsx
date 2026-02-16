import { useEffect, useState } from 'react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useSettingsStore, SettingsMailConfig } from '@/store/settingsStore'
import { useToast } from '@/hooks/use-toast'
import { useMutation } from '@tanstack/react-query'

export function MailSettings() {
  const { toast } = useToast()
  const mailConfig = useSettingsStore((s) => s.mail)
  const updateSettings = useSettingsStore((s) => s.updateSettings)

  const [form, setForm] = useState<SettingsMailConfig>({
    driver: 'smtp',
    host: '',
    port: 587,
    encryption: 'tls',
    username: '',
    password: '',
    from_address: '',
    from_name: '',
  })

  // Initialize form with store data
  useEffect(() => {
    if (mailConfig) {
      setForm({
        driver: mailConfig.driver || 'smtp',
        host: mailConfig.host || '',
        port: mailConfig.port || 587,
        encryption: mailConfig.encryption || 'tls',
        username: mailConfig.username || '',
        password: mailConfig.password || '', // usually masked ********
        from_address: mailConfig.from_address || '',
        from_name: mailConfig.from_name || '',
      })
    }
  }, [mailConfig])

  const saveMutation = useMutation({
    mutationFn: async () => {
      await updateSettings({ mail: form })
    },
    onSuccess: () => {
      toast({ title: 'Saved', description: 'Mail configuration updated successfully' })
    },
    onError: () => {
      toast({ title: 'Error', variant: 'destructive', description: 'Failed to save mail configuration' })
    },
  })

  const handleChange = (field: keyof SettingsMailConfig, value: string | number) => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    setForm((prev) => ({ ...prev, [field]: value as any }))
  }

  return (
    <div className="grid gap-6">
      <Card>
        <CardHeader>
          <CardTitle>SMTP Configuration</CardTitle>
          <CardDescription>Configure your email provider settings (e.g., Gmail, Outlook, Mailgun).</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label>Mail Driver</Label>
              <Select
                value={form.driver}
                onValueChange={(val) => handleChange('driver', val)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="smtp">SMTP</SelectItem>
                  <SelectItem value="sendmail">Sendmail</SelectItem>
                  <SelectItem value="log">Log (Testing)</SelectItem>
                  <SelectItem value="array">Array (Testing)</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Host</Label>
              <Input
                placeholder="smtp.gmail.com"
                value={form.host}
                onChange={(e) => handleChange('host', e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label>Port</Label>
              <Input
                type="number"
                placeholder="587"
                value={form.port}
                onChange={(e) => handleChange('port', Number(e.target.value))}
              />
            </div>

            <div className="space-y-2">
              <Label>Encryption</Label>
              <Select
                value={form.encryption || 'none'}
                onValueChange={(val) => handleChange('encryption', val === 'none' ? '' : val)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select encryption" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="tls">TLS</SelectItem>
                  <SelectItem value="ssl">SSL</SelectItem>
                  <SelectItem value="none">None</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Username</Label>
              <Input
                placeholder="your-email@example.com"
                value={form.username}
                onChange={(e) => handleChange('username', e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label>Password</Label>
              <Input
                type="password"
                placeholder="App Password or SMTP Password"
                value={form.password}
                onChange={(e) => handleChange('password', e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Leave visible as "********" to keep unchanged. Clear to remove.
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Sender Identity</CardTitle>
          <CardDescription>Default "From" address for outgoing emails.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label>From Address</Label>
              <Input
                type="email"
                placeholder="noreply@yourdomain.com"
                value={form.from_address}
                onChange={(e) => handleChange('from_address', e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label>From Name</Label>
              <Input
                placeholder="NoovaERP System"
                value={form.from_name}
                onChange={(e) => handleChange('from_name', e.target.value)}
              />
            </div>
          </div>

          <div className="pt-4 flex justify-end">
            <Button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
              {saveMutation.isPending ? 'Saving...' : 'Save Configuration'}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
