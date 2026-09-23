import { useState } from 'react'
import { Icon } from '@iconify/react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Loader2, Plus, Trash2, Play, CheckCircle2, XCircle } from 'lucide-react'

interface WhatsAppApiKey {
  id: number
  name: string
  description?: string
  platform_name?: string
  platform_url?: string
  whatsapp_provider: 'custom' | 'twilio' | 'vonage'
  api_endpoint?: string
  masked_key: string
  is_active: boolean
  is_expired: boolean
  usage_count: number
  last_used_at?: string
  expires_at?: string
  created_at: string
}

interface NewApiKeyForm {
  name: string
  description: string
  platform_name: string
  platform_url: string
  whatsapp_provider: 'custom' | 'twilio' | 'vonage'
  api_endpoint: string
  api_key: string
  expires_in_days: string
}

interface TestMessageForm {
  phone_number: string
  message: string
  dry_run: boolean
}

const initialFormState: NewApiKeyForm = {
  name: '',
  description: '',
  platform_name: '',
  platform_url: '',
  whatsapp_provider: 'custom',
  api_endpoint: '',
  api_key: '',
  expires_in_days: '',
}

function WhatsAppStat({
  label,
  value,
  icon,
  tile,
}: {
  label: string
  value: number
  icon: string
  tile: string
}) {
  return (
    <Card className="overflow-hidden">
      <CardContent className="flex items-center gap-4 p-5">
        <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${tile}`}>
          <Icon icon={icon} className="h-6 w-6" />
        </div>
        <div>
          <div className="text-2xl font-bold tabular-nums">{value}</div>
          <div className="text-xs text-muted-foreground">{label}</div>
        </div>
      </CardContent>
    </Card>
  )
}

export function WhatsAppSettings() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false)
  const [isTestDialogOpen, setIsTestDialogOpen] = useState(false)
  const [selectedKeyId, setSelectedKeyId] = useState<number | null>(null)
  
  const [formData, setFormData] = useState<NewApiKeyForm>(initialFormState)
  const [testData, setTestData] = useState<TestMessageForm>({
    phone_number: '',
    message: '',
    dry_run: true
  })

  // Fetch API Keys
  const { data, isLoading } = useQuery({
    queryKey: ['whatsapp-api-keys'],
    queryFn: async () => {
      const response = await api.get('/whatsapp/api-keys')
      return response.data
    }
  })

  // Create API Key Mutation
  const createMutation = useMutation({
    mutationFn: async (data: NewApiKeyForm) => {
      const payload: any = { ...data }
      if (payload.expires_in_days) {
        payload.expires_in_days = parseInt(payload.expires_in_days)
      } else {
        delete payload.expires_in_days
      }
      return api.post('/whatsapp/api-keys', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['whatsapp-api-keys'] })
      setIsCreateDialogOpen(false)
      setFormData(initialFormState)
      toast({
        title: 'API Key Created',
        description: 'The new WhatsApp API key has been created successfully.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create API key',
        variant: 'destructive',
      })
    }
  })

  // Toggle Status Mutation
  const toggleStatusMutation = useMutation({
    mutationFn: async ({ id, isActive }: { id: number, isActive: boolean }) => {
      const action = isActive ? 'activate' : 'revoke'
      return api.post(`/whatsapp/api-keys/${id}/${action}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['whatsapp-api-keys'] })
      toast({
        title: 'Status Updated',
        description: 'API key status has been updated.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update status',
        variant: 'destructive',
      })
    }
  })

  // Delete Mutation
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/whatsapp/api-keys/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['whatsapp-api-keys'] })
      toast({
        title: 'API Key Deleted',
        description: 'The API key has been permanently deleted.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete API key',
        variant: 'destructive',
      })
    }
  })

  // Test Message Mutation
  const testMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number, data: TestMessageForm }) => {
      return api.post(`/whatsapp/api-keys/${id}/test`, data)
    },
    onSuccess: (response: any) => {
      if (response.data?.simulated) {
        toast({
          title: 'Test Simulated',
          description: 'Simulation successful. No message was sent.',
        })
      } else {
        toast({
          title: 'Message Sent',
          description: 'Test message sent successfully.',
        })
      }
      setIsTestDialogOpen(false)
    },
    onError: (error: any) => {
      toast({
        title: 'Test Failed',
        description: error.response?.data?.details || error.response?.data?.message || 'Failed to send test message',
        variant: 'destructive',
      })
    }
  })

  const handleCreateSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    createMutation.mutate(formData)
  }

  const handleTestSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (selectedKeyId) {
      testMutation.mutate({ id: selectedKeyId, data: testData })
    }
  }

  const openTestDialog = (id: number) => {
    setSelectedKeyId(id)
    setTestData({ ...testData, phone_number: '', message: '' })
    setIsTestDialogOpen(true)
  }

  const apiKeys = data?.api_keys || []

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">WhatsApp Integration</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Manage your WhatsApp API keys and providers.
          </p>
        </div>
        <Button onClick={() => setIsCreateDialogOpen(true)}>
          <Plus className="mr-2 h-4 w-4" /> New API Key
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <WhatsAppStat label="Total keys" value={data?.stats?.total || 0} icon="solar:key-bold-duotone" tile="bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300" />
        <WhatsAppStat label="Active keys" value={data?.stats?.active || 0} icon="solar:check-circle-bold-duotone" tile="bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300" />
        <WhatsAppStat label="Expired keys" value={data?.stats?.expired || 0} icon="solar:close-circle-bold-duotone" tile="bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300" />
        <WhatsAppStat label="Total calls" value={data?.stats?.total_api_calls || 0} icon="solar:phone-calling-bold-duotone" tile="bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300" />
     </div>

      <Card>
        <CardHeader>
          <CardTitle>API Keys</CardTitle>
          <CardDescription>
            List of all WhatsApp API keys configured for your store.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex justify-center p-8">
              <Loader2 className="h-8 w-8 animate-spin" />
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Provider</TableHead>
                  <TableHead>Platform</TableHead>
                  <TableHead>Key Prefix</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Downloads</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {apiKeys.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                      No API keys found. Create one to get started.
                    </TableCell>
                  </TableRow>
                ) : (
                  apiKeys.map((key: WhatsAppApiKey) => (
                    <TableRow key={key.id}>
                      <TableCell className="font-medium">
                        {key.name}
                        {key.description && (
                          <div className="text-xs text-muted-foreground">{key.description}</div>
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className="capitalize">
                          {key.whatsapp_provider}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {key.platform_name || '-'}
                        {key.platform_url && (
                          <a href={key.platform_url} target="_blank" rel="noreferrer" className="block text-xs text-blue-500 hover:underline truncate max-w-[150px]">
                            {key.platform_url}
                          </a>
                        )}
                      </TableCell>
                      <TableCell className="font-mono text-xs">
                        {key.masked_key}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <Switch 
                            checked={key.is_active && !key.is_expired} 
                            onCheckedChange={(checked) => toggleStatusMutation.mutate({ id: key.id, isActive: checked })}
                            disabled={key.is_expired}
                          />
                          <span className={`text-xs ${key.is_active && !key.is_expired ? 'text-green-600' : 'text-muted-foreground'}`}>
                            {key.is_expired ? 'Expired' : (key.is_active ? 'Active' : 'Inactive')}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="secondary">{key.usage_count}</Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="icon" variant="ghost" title="Test Connection" onClick={() => openTestDialog(key.id)}>
                            <Play className="h-4 w-4" />
                          </Button>
                          <Button 
                            size="icon" 
                            variant="ghost" 
                            className="text-red-500 hover:text-red-600 hover:bg-red-50"
                            onClick={() => {
                                if (confirm('Are you sure you want to delete this API key? This cannot be undone.')) {
                                    deleteMutation.mutate(key.id)
                                }
                            }}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Create Dialog */}
      <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
        <DialogContent className="sm:max-w-[600px]">
          <DialogHeader>
            <DialogTitle>Create WhatsApp API Key</DialogTitle>
            <DialogDescription>
              Configure a new WhatsApp integration for your store.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleCreateSubmit} className="space-y-4">
            <div className="grid gap-4 py-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Friendly Name</Label>
                  <Input 
                    id="name" 
                    placeholder="e.g. Main Store WhatsApp" 
                    required 
                    value={formData.name}
                    onChange={(e) => setFormData({...formData, name: e.target.value})}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="provider">Provider</Label>
                  <Select 
                    value={formData.whatsapp_provider} 
                    onValueChange={(val: any) => setFormData({...formData, whatsapp_provider: val})}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select provider" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="custom">Custom Implementation</SelectItem>
                      <SelectItem value="twilio">Twilio</SelectItem>
                      <SelectItem value="vonage">Vonage (Nexmo)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="description">Description (Optional)</Label>
                <Input 
                  id="description" 
                  placeholder="Notes about this integration" 
                  value={formData.description}
                  onChange={(e) => setFormData({...formData, description: e.target.value})}
                />
              </div>

              {formData.whatsapp_provider === 'custom' && (
                <div className="space-y-2">
                   <Label htmlFor="api_endpoint">API Endpoint URL</Label>
                   <Input 
                     id="api_endpoint" 
                     placeholder="https://your-whatsapp-service.com/api/send" 
                     value={formData.api_endpoint}
                     onChange={(e) => setFormData({...formData, api_endpoint: e.target.value})}
                   />
                   <p className="text-[0.8rem] text-muted-foreground">The URL where we will send webhook events.</p>
                </div>
              )}

              {(formData.whatsapp_provider === 'twilio' || formData.whatsapp_provider === 'vonage') && (
                <div className="space-y-2">
                   <Label htmlFor="api_key">
                     {formData.whatsapp_provider === 'twilio' ? 'Account SID:Auth Token' : 'API Key:API Secret'}
                   </Label>
                   <Input 
                     id="api_key" 
                     type="password"
                     placeholder={formData.whatsapp_provider === 'twilio' ? 'ACxxxxx:auth_token' : 'key:secret'}
                     required
                     value={formData.api_key}
                     onChange={(e) => setFormData({...formData, api_key: e.target.value})}
                   />
                </div>
              )}

              <div className="border-t pt-4 mt-2">
                <h4 className="text-sm font-medium mb-3">Platform Information (Optional)</h4>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="platform_name">Platform Name</Label>
                    <Input 
                      id="platform_name" 
                      placeholder="e.g. WooCommerce" 
                      value={formData.platform_name}
                      onChange={(e) => setFormData({...formData, platform_name: e.target.value})}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="platform_url">Store URL</Label>
                    <Input 
                      id="platform_url" 
                      placeholder="https://mystore.com" 
                      value={formData.platform_url}
                      onChange={(e) => setFormData({...formData, platform_url: e.target.value})}
                    />
                  </div>
                </div>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setIsCreateDialogOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Create API Key
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Test Dialog */}
      <Dialog open={isTestDialogOpen} onOpenChange={setIsTestDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Test WhatsApp Connection</DialogTitle>
            <DialogDescription>
              Send a test message to verify your configuration.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleTestSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="phone">Phone Number (with country code)</Label>
              <Input 
                id="phone" 
                placeholder="e.g. 60123456789" 
                required 
                value={testData.phone_number}
                onChange={(e) => setTestData({...testData, phone_number: e.target.value})}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="message">Message (Optional)</Label>
              <Textarea 
                id="message" 
                placeholder="Leave blank to send a default test message" 
                value={testData.message}
                onChange={(e) => setTestData({...testData, message: e.target.value})}
              />
            </div>
            <div className="flex items-center space-x-2">
              <Switch 
                id="dry_run" 
                checked={testData.dry_run}
                onCheckedChange={(checked) => setTestData({...testData, dry_run: checked})}
              />
              <div className="grid gap-1.5 leading-none">
                 <Label htmlFor="dry_run">Dry Run (Simulate only)</Label>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setIsTestDialogOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={testMutation.isPending}>
                {testMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Send Test
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
