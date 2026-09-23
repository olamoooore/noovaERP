import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { useSettingsStore, SettingsMailConfig } from '@/store/settingsStore'
import { useToast } from '@/hooks/use-toast'
import { useMutation } from '@tanstack/react-query'
import api from '@/lib/api'
import { cn } from '@/lib/utils'

interface MailTestResult {
  success: boolean
  message: string
  details: {
    socket?: string
    auth?: string
    server_info?: Record<string, string | string[]>
    error?: string
    driver?: string
    recipient?: string
    from_address?: string
    round_trip_ms?: number
  }
}

const DRIVER_OPTIONS: Array<{
  value: SettingsMailConfig['driver']
  label: string
  description: string
  icon: string
}> = [
  {
    value: 'smtp',
    label: 'SMTP',
    description: 'Your own mail server or provider (Gmail, GoDaddy, Mailgun…)',
    icon: 'solar:server-square-cloud-bold-duotone',
  },
  {
    value: 'resend',
    label: 'Resend',
    description: 'Transactional email API — no server maintenance required',
    icon: 'solar:letter-opened-bold-duotone',
  },
]

const TESTING_DRIVERS: Array<{ value: SettingsMailConfig['driver']; label: string }> = [
  { value: 'log', label: 'Log (testing — writes to laravel.log)' },
  { value: 'array', label: 'Array (testing — in-memory)' },
  { value: 'sendmail', label: 'Sendmail (local MTA)' },
]

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
    api_key: '',
    from_address: '',
    from_name: '',
  })

  const [testResult, setTestResult] = useState<MailTestResult | null>(null)

  useEffect(() => {
    if (mailConfig) {
      setForm({
        driver: mailConfig.driver || 'smtp',
        host: mailConfig.host || '',
        port: mailConfig.port || 587,
        encryption: mailConfig.encryption || 'tls',
        username: mailConfig.username || '',
        password: mailConfig.password || '',
        api_key: mailConfig.api_key || '',
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

  const testMutation = useMutation({
    mutationFn: async (): Promise<MailTestResult> => {
      const response = await api.post('/settings/test-smtp', {
        driver: form.driver,
        host: form.host,
        port: form.port,
        encryption: form.encryption || 'none',
        username: form.username,
        password: form.password,
        api_key: form.api_key,
        from_address: form.from_address,
      })
      return response.data
    },
    onSuccess: (data) => {
      setTestResult(data)
      if (data.success) {
        toast({ title: 'Success', description: data.message })
      } else {
        toast({ title: 'Connection Failed', variant: 'destructive', description: data.message })
      }
    },
    onError: (error: any) => {
      const errorMessage = error.response?.data?.message || error.message || 'Failed to test mail connection'
      const errorDetails = error.response?.data?.details || { error: error.message }

      setTestResult({ success: false, message: errorMessage, details: errorDetails })
      toast({ title: 'Connection Failed', variant: 'destructive', description: errorMessage })
    },
  })

  const handleChange = (field: keyof SettingsMailConfig, value: string | number) => {
    setForm((prev) => ({ ...prev, [field]: value as any }))
    setTestResult(null)
  }

  const isResend = form.driver === 'resend'
  const isRealDriver = form.driver === 'smtp' || form.driver === 'resend'

  const testDisabled =
    testMutation.isPending ||
    (isResend ? !form.api_key : form.driver === 'smtp' ? !form.host || !form.username : false)

  return (
    <div className="grid gap-6">
      <Card className="overflow-hidden">
        <CardHeader className="border-b bg-muted/20">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Icon icon="solar:letter-bold-duotone" className="h-5 w-5 text-primary" />
            </div>
            <div className="space-y-1">
              <CardTitle className="text-lg leading-none">Mail Transport</CardTitle>
              <CardDescription className="text-sm leading-relaxed">
                Choose how the ERP delivers email — your own SMTP server, or the Resend API.
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-6 pt-6">
          {/* Driver selector */}
          <div className="grid gap-3 sm:grid-cols-2">
            {DRIVER_OPTIONS.map((opt) => {
              const active = form.driver === opt.value
              return (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => handleChange('driver', opt.value)}
                  className={cn(
                    'flex items-start gap-3 rounded-lg border p-4 text-left transition-all',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    active
                      ? 'border-primary/50 bg-primary/5 shadow-sm ring-1 ring-primary/30'
                      : 'border-border hover:border-primary/30 hover:bg-muted/40',
                  )}
                >
                  <div
                    className={cn(
                      'flex h-9 w-9 shrink-0 items-center justify-center rounded-md transition-colors',
                      active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                    )}
                  >
                    <Icon icon={opt.icon} className="h-5 w-5" />
                  </div>
                  <div className="min-w-0 flex-1 space-y-0.5">
                    <div className="flex items-center gap-2">
                      <span className={cn('text-sm font-semibold', active && 'text-primary')}>
                        {opt.label}
                      </span>
                      {active && (
                        <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-primary" />
                      )}
                    </div>
                    <p className="text-xs leading-relaxed text-muted-foreground">{opt.description}</p>
                  </div>
                </button>
              )
            })}
          </div>

          {/* SMTP fields */}
          {form.driver === 'smtp' && (
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label>Host</Label>
                <Input
                  placeholder="smtpout.secureserver.net"
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
                    <SelectItem value="tls">TLS (STARTTLS, port 587)</SelectItem>
                    <SelectItem value="ssl">SSL (implicit, port 465)</SelectItem>
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

              <div className="space-y-2 md:col-span-2">
                <Label>Password</Label>
                <Input
                  type="password"
                  placeholder="App Password or SMTP Password"
                  value={form.password}
                  onChange={(e) => handleChange('password', e.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                  Leave as "********" to keep the stored password unchanged.
                </p>
              </div>
            </div>
          )}

          {/* Resend fields */}
          {isResend && (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label>Resend API Key</Label>
                <Input
                  type="password"
                  placeholder="re_..."
                  value={form.api_key}
                  onChange={(e) => handleChange('api_key', e.target.value)}
                  className="font-mono"
                />
                <p className="text-xs text-muted-foreground">
                  Create a key at resend.com/api-keys. Leave as "********" to keep the stored key unchanged.
                </p>
              </div>

              <div className="flex items-start gap-2 rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                <Icon icon="solar:info-circle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                <div className="space-y-1">
                  <p>
                    The From Address below must belong to a <strong>verified domain</strong> in your
                    Resend account, otherwise delivery is rejected.
                  </p>
                  <p>
                    Until a domain is verified, Resend only delivers to the account owner's address from
                    <code className="mx-1 rounded bg-background px-1 font-mono ring-1 ring-border">onboarding@resend.dev</code>.
                  </p>
                </div>
              </div>
            </div>
          )}

          {/* Testing-log drivers (kept for local development) */}
          {!isRealDriver && (
            <div className="space-y-2">
              <Label>Testing driver</Label>
              <Select
                value={form.driver}
                onValueChange={(val) => handleChange('driver', val)}
              >
                <SelectTrigger className="max-w-md">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TESTING_DRIVERS.map((d) => (
                    <SelectItem key={d.value} value={d.value}>
                      {d.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Testing drivers do not deliver real email — they are for local development only.
              </p>
            </div>
          )}
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
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Test &amp; Save</CardTitle>
          <CardDescription>
            {isResend
              ? 'Validates the API key and sends a real test email through Resend.'
              : 'Verifies the mail server is reachable and credentials are valid.'}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center gap-3">
            <Button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
              {saveMutation.isPending ? (
                <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
              ) : (
                <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
              )}
              Save Configuration
            </Button>

            <Button variant="outline" onClick={() => testMutation.mutate()} disabled={testDisabled}>
              {testMutation.isPending ? (
                <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
              ) : (
                <Icon icon="solar:test-tube-bold-duotone" className="mr-2 h-4 w-4" />
              )}
              Test Connection
            </Button>

            <p className="text-sm text-muted-foreground">
              {isResend
                ? 'Test sends an email to the From Address above.'
                : 'Test performs a live SMTP handshake with AUTH.'}
            </p>
          </div>

          {testResult && (
            <Alert variant={testResult.success ? 'default' : 'destructive'}>
              {testResult.success ? (
                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
              ) : (
                <Icon icon="solar:close-circle-bold-duotone" className="h-4 w-4" />
              )}
              <AlertTitle>
                {testResult.success ? 'Connection Successful' : 'Connection Failed'}
              </AlertTitle>
              <AlertDescription>
                <p>{testResult.message}</p>
                {testResult.details && Object.keys(testResult.details).length > 0 && (
                  <div className="mt-2 space-y-1 text-xs">
                    {testResult.details.driver && (
                      <p><strong>Driver:</strong> {testResult.details.driver}</p>
                    )}
                    {testResult.details.socket && (
                      <p><strong>Socket:</strong> {testResult.details.socket}</p>
                    )}
                    {testResult.details.auth && (
                      <p><strong>Auth:</strong> {testResult.details.auth}</p>
                    )}
                    {testResult.details.recipient && (
                      <p><strong>Recipient:</strong> {testResult.details.recipient}</p>
                    )}
                    {testResult.details.round_trip_ms !== undefined && (
                      <p><strong>Round trip:</strong> {testResult.details.round_trip_ms} ms</p>
                    )}
                    {testResult.details.error && (
                      <p><strong>Error:</strong> {testResult.details.error}</p>
                    )}
                  </div>
                )}
              </AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
