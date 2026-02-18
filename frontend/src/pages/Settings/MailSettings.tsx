import { useEffect, useState } from 'react'
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
import { CheckCircle2, XCircle, Loader2 } from 'lucide-react'

interface SmtpTestResult {
  success: boolean
  message: string
  details: {
    socket?: string
    auth?: string
    server_info?: Record<string, string | string[]>
    error?: string
  }
}

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

  const [testResult, setTestResult] = useState<SmtpTestResult | null>(null)

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

  const testSmtpMutation = useMutation({
    mutationFn: async (): Promise<SmtpTestResult> => {
      const response = await api.post('/settings/test-smtp', {
        host: form.host,
        port: form.port,
        encryption: form.encryption || 'none',
        username: form.username,
        password: form.password,
        from_address: form.from_address,
      })
      return response.data
    },
    onSuccess: (data) => {
      setTestResult(data)
      if (data.success) {
        toast({ title: 'Success', description: 'SMTP connection test successful' })
      } else {
        toast({ title: 'Connection Failed', variant: 'destructive', description: data.message })
      }
    },
    onError: (error: Error) => {
      setTestResult({
        success: false,
        message: error.message || 'Failed to test SMTP connection',
        details: { error: error.message },
      })
      toast({ title: 'Error', variant: 'destructive', description: 'Failed to test SMTP connection' })
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

      <Card>
        <CardHeader>
          <CardTitle>Test Connection</CardTitle>
          <CardDescription>Test your SMTP settings to verify connectivity.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-4">
            <Button
              variant="outline"
              onClick={() => testSmtpMutation.mutate()}
              disabled={testSmtpMutation.isPending || !form.host || !form.username}
            >
              {testSmtpMutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Testing...
                </>
              ) : (
                'Test Connection'
              )}
            </Button>
            <p className="text-sm text-muted-foreground">
              Click to verify SMTP server connectivity and authentication.
            </p>
          </div>

          {testResult && (
            <Alert variant={testResult.success ? 'default' : 'destructive'}>
              <div className="flex items-start gap-2">
                {testResult.success ? (
                  <CheckCircle2 className="h-5 w-5 text-green-600 mt-0.5" />
                ) : (
                  <XCircle className="h-5 w-5 mt-0.5" />
                )}
                <div className="flex-1">
                  <AlertTitle>{testResult.success ? 'Connection Successful' : 'Connection Failed'}</AlertTitle>
                  <AlertDescription className="mt-2">
                    <p>{testResult.message}</p>
                    {testResult.details && (
                      <div className="mt-3 text-sm">
                        {testResult.details.socket && (
                          <p><span className="font-medium">Socket:</span> {testResult.details.socket}</p>
                        )}
                        {testResult.details.auth && (
                          <p><span className="font-medium">Auth:</span> {testResult.details.auth}</p>
                        )}
                        {testResult.details.server_info && Object.keys(testResult.details.server_info).length > 0 && (
                          <div className="mt-2">
                            <p className="font-medium">Server Info:</p>
                            <pre className="mt-1 p-2 bg-muted rounded text-xs overflow-auto">
                              {JSON.stringify(testResult.details.server_info, null, 2)}
                            </pre>
                          </div>
                        )}
                        {testResult.details.error && (
                          <p className="text-destructive"><span className="font-medium">Error:</span> {testResult.details.error}</p>
                        )}
                      </div>
                    )}
                  </AlertDescription>
                </div>
              </div>
            </Alert>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
