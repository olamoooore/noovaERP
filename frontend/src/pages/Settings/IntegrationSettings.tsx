import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'

export function IntegrationSettings() {
  const { toast } = useToast()
  
  // Payment Gateways
  const [stripeEnabled, setStripeEnabled] = useState(true)
  const [stripePublicKey, setStripePublicKey] = useState('')
  const [stripeSecretKey, setStripeSecretKey] = useState('')
  
  const [paypalEnabled, setPaypalEnabled] = useState(false)
  const [paypalClientId, setPaypalClientId] = useState('')
  const [paypalSecret, setPaypalSecret] = useState('')
  
  // Email Service
  const [emailProvider, setEmailProvider] = useState('sendgrid')
  const [sendgridApiKey, setSendgridApiKey] = useState('')
  const [smtpHost, setSmtpHost] = useState('')
  const [smtpPort, setSmtpPort] = useState('587')
  const [smtpUsername, setSmtpUsername] = useState('')
  const [smtpPassword, setSmtpPassword] = useState('')
  
  // SMS Service
  const [twilioEnabled, setTwilioEnabled] = useState(false)
  const [twilioAccountSid, setTwilioAccountSid] = useState('')
  const [twilioAuthToken, setTwilioAuthToken] = useState('')
  const [twilioPhoneNumber, setTwilioPhoneNumber] = useState('')
  
  // Analytics
  const [googleAnalyticsEnabled, setGoogleAnalyticsEnabled] = useState(false)
  const [googleAnalyticsId, setGoogleAnalyticsId] = useState('')

  const handleSave = () => {
    toast({
      title: 'Settings saved',
      description: 'Integration settings have been updated.',
    })
  }

  const handleTestConnection = (service: string) => {
    toast({
      title: 'Testing connection',
      description: `Testing ${service} connection...`,
    })
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-medium">Integration Settings</h3>
        <p className="text-sm text-muted-foreground">
          Connect third-party services for payments, communications, and analytics.
        </p>
      </div>

      <div className="grid gap-6">
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle>Stripe Payment Gateway</CardTitle>
                <CardDescription>
                  Accept credit card payments through Stripe.
                </CardDescription>
              </div>
              <Badge variant={stripeEnabled ? "default" : "secondary"}>
                {stripeEnabled ? "Active" : "Inactive"}
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <Label>Enable Stripe</Label>
              <Switch 
                checked={stripeEnabled}
                onCheckedChange={setStripeEnabled}
              />
            </div>

            {stripeEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="stripePublicKey">Publishable Key</Label>
                  <Input 
                    id="stripePublicKey"
                    type="password"
                    value={stripePublicKey} 
                    onChange={(e) => setStripePublicKey(e.target.value)} 
                    placeholder="pk_test_..."
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="stripeSecretKey">Secret Key</Label>
                  <Input 
                    id="stripeSecretKey"
                    type="password"
                    value={stripeSecretKey} 
                    onChange={(e) => setStripeSecretKey(e.target.value)} 
                    placeholder="sk_test_..."
                  />
                </div>

                <Button 
                  variant="outline" 
                  onClick={() => handleTestConnection('Stripe')}
                >
                  Test Connection
                </Button>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle>PayPal Payment Gateway</CardTitle>
                <CardDescription>
                  Accept PayPal payments from customers.
                </CardDescription>
              </div>
              <Badge variant={paypalEnabled ? "default" : "secondary"}>
                {paypalEnabled ? "Active" : "Inactive"}
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <Label>Enable PayPal</Label>
              <Switch 
                checked={paypalEnabled}
                onCheckedChange={setPaypalEnabled}
              />
            </div>

            {paypalEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="paypalClientId">Client ID</Label>
                  <Input 
                    id="paypalClientId"
                    type="password"
                    value={paypalClientId} 
                    onChange={(e) => setPaypalClientId(e.target.value)} 
                    placeholder="AYSq3RDGsmBLJE-otTkBtM-jBRd1TCQwFf9RGfwddNXWz0uFU9ztymylOhRS"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="paypalSecret">Secret</Label>
                  <Input 
                    id="paypalSecret"
                    type="password"
                    value={paypalSecret} 
                    onChange={(e) => setPaypalSecret(e.target.value)} 
                    placeholder="EGnHDxD_qRPdaLdZz8iCr8N7_MzF-YHPTkjs6NKYQvQSBngp4PTTVWkPZRbL"
                  />
                </div>

                <Button 
                  variant="outline" 
                  onClick={() => handleTestConnection('PayPal')}
                >
                  Test Connection
                </Button>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Email Service Provider</CardTitle>
            <CardDescription>
              Configure email delivery for notifications and confirmations.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="emailProvider">Email Provider</Label>
              <select 
                id="emailProvider"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                value={emailProvider}
                onChange={(e) => setEmailProvider(e.target.value)}
              >
                <option value="sendgrid">SendGrid</option>
                <option value="smtp">SMTP</option>
                <option value="mailgun">Mailgun</option>
                <option value="ses">Amazon SES</option>
              </select>
            </div>

            {emailProvider === 'sendgrid' && (
              <div className="space-y-2">
                <Label htmlFor="sendgridApiKey">SendGrid API Key</Label>
                <Input 
                  id="sendgridApiKey"
                  type="password"
                  value={sendgridApiKey} 
                  onChange={(e) => setSendgridApiKey(e.target.value)} 
                  placeholder="SG...."
                />
              </div>
            )}

            {emailProvider === 'smtp' && (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="smtpHost">SMTP Host</Label>
                    <Input 
                      id="smtpHost"
                      value={smtpHost} 
                      onChange={(e) => setSmtpHost(e.target.value)} 
                      placeholder="smtp.gmail.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="smtpPort">SMTP Port</Label>
                    <Input 
                      id="smtpPort"
                      value={smtpPort} 
                      onChange={(e) => setSmtpPort(e.target.value)} 
                      placeholder="587"
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="smtpUsername">SMTP Username</Label>
                  <Input 
                    id="smtpUsername"
                    value={smtpUsername} 
                    onChange={(e) => setSmtpUsername(e.target.value)} 
                    placeholder="your-email@example.com"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="smtpPassword">SMTP Password</Label>
                  <Input 
                    id="smtpPassword"
                    type="password"
                    value={smtpPassword} 
                    onChange={(e) => setSmtpPassword(e.target.value)} 
                    placeholder="••••••••"
                  />
                </div>
              </>
            )}

            <Button 
              variant="outline" 
              onClick={() => handleTestConnection('Email Service')}
            >
              Send Test Email
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle>Twilio SMS Service</CardTitle>
                <CardDescription>
                  Send SMS notifications via Twilio.
                </CardDescription>
              </div>
              <Badge variant={twilioEnabled ? "default" : "secondary"}>
                {twilioEnabled ? "Active" : "Inactive"}
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <Label>Enable Twilio SMS</Label>
              <Switch 
                checked={twilioEnabled}
                onCheckedChange={setTwilioEnabled}
              />
            </div>

            {twilioEnabled && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="twilioAccountSid">Account SID</Label>
                  <Input 
                    id="twilioAccountSid"
                    type="password"
                    value={twilioAccountSid} 
                    onChange={(e) => setTwilioAccountSid(e.target.value)} 
                    placeholder="AC..."
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="twilioAuthToken">Auth Token</Label>
                  <Input 
                    id="twilioAuthToken"
                    type="password"
                    value={twilioAuthToken} 
                    onChange={(e) => setTwilioAuthToken(e.target.value)} 
                    placeholder="••••••••"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="twilioPhoneNumber">Twilio Phone Number</Label>
                  <Input 
                    id="twilioPhoneNumber"
                    value={twilioPhoneNumber} 
                    onChange={(e) => setTwilioPhoneNumber(e.target.value)} 
                    placeholder="+1 (555) 123-4567"
                  />
                </div>

                <Button 
                  variant="outline" 
                  onClick={() => handleTestConnection('Twilio')}
                >
                  Send Test SMS
                </Button>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle>Google Analytics</CardTitle>
                <CardDescription>
                  Track website and event analytics.
                </CardDescription>
              </div>
              <Badge variant={googleAnalyticsEnabled ? "default" : "secondary"}>
                {googleAnalyticsEnabled ? "Active" : "Inactive"}
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <Label>Enable Google Analytics</Label>
              <Switch 
                checked={googleAnalyticsEnabled}
                onCheckedChange={setGoogleAnalyticsEnabled}
              />
            </div>

            {googleAnalyticsEnabled && (
              <div className="space-y-2">
                <Label htmlFor="googleAnalyticsId">Tracking ID</Label>
                <Input 
                  id="googleAnalyticsId"
                  value={googleAnalyticsId} 
                  onChange={(e) => setGoogleAnalyticsId(e.target.value)} 
                  placeholder="G-XXXXXXXXXX or UA-XXXXXXXXX-X"
                />
                <p className="text-xs text-muted-foreground">
                  Your Google Analytics measurement ID or tracking ID.
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button onClick={handleSave}>Save Changes</Button>
        </div>
      </div>
    </div>
  )
}
