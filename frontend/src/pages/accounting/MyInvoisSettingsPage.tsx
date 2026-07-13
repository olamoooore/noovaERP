import { useState } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'

export default function MyInvoisSettingsPage() {
  const { toast } = useToast()
  const [config, setConfig] = useState({
    enabled: false,
    company_tin: '',
    company_name: '',
    msic_code: '',
    business_activity: '',
    submission_mode: 'test', // test or production
  })

  const handleSave = () => {
    toast({ title: 'Settings saved', description: 'MyInvois configuration updated.' })
  }

  return (
    <div className="container mx-auto p-6 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle>MyInvois E-Invoice Settings</CardTitle>
          <CardDescription>Configure your MyInvois integration for Malaysia LHDN compliance</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="enabled"
              checked={config.enabled}
              onChange={(e) => setConfig({ ...config, enabled: e.target.checked })}
            />
            <Label htmlFor="enabled">Enable MyInvois Integration</Label>
          </div>
          <div className="space-y-2">
            <Label>Company TIN</Label>
            <Input
              value={config.company_tin}
              onChange={(e) => setConfig({ ...config, company_tin: e.target.value })}
              placeholder="e.g. C23012345678"
            />
          </div>
          <div className="space-y-2">
            <Label>Company Name</Label>
            <Input
              value={config.company_name}
              onChange={(e) => setConfig({ ...config, company_name: e.target.value })}
              placeholder="Company name as registered with LHDN"
            />
          </div>
          <div className="space-y-2">
            <Label>MSIC Code</Label>
            <Input
              value={config.msic_code}
              onChange={(e) => setConfig({ ...config, msic_code: e.target.value })}
              placeholder="Malaysia Standard Industrial Classification code"
            />
          </div>
          <div className="space-y-2">
            <Label>Business Activity</Label>
            <Input
              value={config.business_activity}
              onChange={(e) => setConfig({ ...config, business_activity: e.target.value })}
              placeholder="Description of business activity"
            />
          </div>
          <div className="space-y-2">
            <Label>Submission Mode</Label>
            <select
              className="w-full border rounded px-3 py-2"
              value={config.submission_mode}
              onChange={(e) => setConfig({ ...config, submission_mode: e.target.value })}
            >
              <option value="test">Test (Sandbox)</option>
              <option value="production">Production</option>
            </select>
          </div>
          <Button onClick={handleSave}>Save Settings</Button>
        </CardContent>
      </Card>
    </div>
  )
}
