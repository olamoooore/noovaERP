import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'

export function GeneralSettings() {
  const { toast } = useToast()
  
  // In a real app, these would come from the store or API
  const [companyName, setCompanyName] = useState('NoovaERP')
  const [logoUrl, setLogoUrl] = useState('')

  const handleSave = () => {
    // Mock save for now, or implement API call if backend supports it
    toast({
      title: 'Settings saved',
      description: 'General settings have been updated.',
    })
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
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button onClick={handleSave}>Save Changes</Button>
        </div>
      </div>
    </div>
  )
}
