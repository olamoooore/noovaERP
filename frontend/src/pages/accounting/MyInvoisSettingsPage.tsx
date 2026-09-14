import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Icon } from '@iconify/react'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export default function MyInvoisSettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: configData, isLoading } = useQuery({
    queryKey: ['myinvois-config'],
    queryFn: async () => {
      const res = await api.get('/accounting/compliance/config/MY')
      return res.data?.data
    },
  })

  const [form, setForm] = useState({
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
    supplier_is_individual: false,
  })

  useEffect(() => {
    if (configData?.credentials) {
      const c = configData.credentials
      setForm({
        myinvoice_client_id: c.myinvoice_client_id || '',
        myinvoice_client_secret: '', // never populated from server
        supplier_tin: c.supplier_tin || '',
        supplier_name: c.supplier_name || '',
        supplier_id_type: c.supplier_id_type || 'BRN',
        supplier_id_number: c.supplier_id_number || '',
        supplier_msic_code: c.supplier_msic_code || '',
        supplier_business_activity_description: c.supplier_business_activity_description || '',
        supplier_phone: c.supplier_phone || '',
        supplier_email: c.supplier_email || '',
        supplier_is_individual: c.supplier_is_individual || false,
      })
    }
  }, [configData])

  const saveMutation = useMutation({
    mutationFn: async () => {
      const { myinvoice_client_secret: clientSecret, ...nonSecretCredentials } = form
      const credentials = {
        ...nonSecretCredentials,
        ...(clientSecret ? { myinvoice_client_secret: clientSecret } : {}),
      }
      const payload = {
        is_enabled: true,
        test_mode: configData?.test_mode ?? true,
        provider_name: 'myinvoice',
        credentials,
      }
      const res = await api.put('/accounting/compliance/config/MY', payload)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['myinvois-config'] })
      toast({ title: 'Credentials saved', description: 'MyInvois LHDN credentials updated successfully.' })
    },
    onError: (err: any) => {
      toast({ title: 'Save failed', description: err?.response?.data?.message || 'Unknown error', variant: 'destructive' })
    },
  })

  if (isLoading) {
    return <div className="p-6">Loading...</div>
  }

  const missing = configData?.missing_credentials || []
  const complete = configData?.credentials_complete

  return (
    <div className="container mx-auto p-6 max-w-3xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            MyInvois E-Invoice Credentials
            {complete ? (
              <Badge className="bg-green-100 text-green-700">Complete</Badge>
            ) : (
              <Badge variant="destructive">{missing.length} missing</Badge>
            )}
          </CardTitle>
          <CardDescription>
            Configure your Malaysia LHDN MyInvois API credentials. These are required to submit e-invoices.
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
                  value={form.myinvoice_client_id}
                  onChange={(e) => setForm({ ...form, myinvoice_client_id: e.target.value })}
                  placeholder="LHDN API Client ID"
                />
              </div>
              <div className="space-y-2">
                <Label>Client Secret</Label>
                <Input
                  type="password"
                  value={form.myinvoice_client_secret}
                  onChange={(e) => setForm({ ...form, myinvoice_client_secret: e.target.value })}
                  placeholder={configData?.credentials?.myinvoice_client_secret_set ? '•••••••• (set)' : 'Enter client secret'}
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
                  value={form.supplier_tin}
                  onChange={(e) => setForm({ ...form, supplier_tin: e.target.value })}
                  placeholder="e.g. C23012345678"
                />
              </div>
              <div className="space-y-2">
                <Label>Supplier Name</Label>
                <Input
                  value={form.supplier_name}
                  onChange={(e) => setForm({ ...form, supplier_name: e.target.value })}
                  placeholder="Company name as registered with LHDN"
                />
              </div>
              <div className="space-y-2">
                <Label>ID Type</Label>
                <select
                  className="w-full border rounded px-3 py-2 text-sm"
                  value={form.supplier_id_type}
                  onChange={(e) => setForm({ ...form, supplier_id_type: e.target.value })}
                >
                  <option value="BRN">Business Registration Number (BRN)</option>
                  <option value="NRIC">NRIC</option>
                  <option value="PASSPORT">Passport</option>
                  <option value="ARMY">Army ID</option>
                  <option value="TIN">TIN</option>
                </select>
              </div>
              <div className="space-y-2">
                <Label>ID Number</Label>
                <Input
                  value={form.supplier_id_number}
                  onChange={(e) => setForm({ ...form, supplier_id_number: e.target.value })}
                  placeholder="Registration / IC number"
                />
              </div>
              <div className="space-y-2">
                <Label>MSIC Code</Label>
                <Input
                  value={form.supplier_msic_code}
                  onChange={(e) => setForm({ ...form, supplier_msic_code: e.target.value })}
                  placeholder="e.g. 62010"
                />
              </div>
              <div className="space-y-2">
                <Label>Business Activity</Label>
                <Input
                  value={form.supplier_business_activity_description}
                  onChange={(e) => setForm({ ...form, supplier_business_activity_description: e.target.value })}
                  placeholder="e.g. Software Development"
                />
              </div>
              <div className="space-y-2">
                <Label>Phone</Label>
                <Input
                  value={form.supplier_phone}
                  onChange={(e) => setForm({ ...form, supplier_phone: e.target.value })}
                  placeholder="+60123456789"
                />
              </div>
              <div className="space-y-2">
                <Label>Email</Label>
                <Input
                  type="email"
                  value={form.supplier_email}
                  onChange={(e) => setForm({ ...form, supplier_email: e.target.value })}
                  placeholder="company@example.com"
                />
              </div>
            </div>
          </div>

          <Button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
            {saveMutation.isPending ? (
              <><Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" /> Saving...</>
            ) : (
              <><Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" /> Save Credentials</>
            )}
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
