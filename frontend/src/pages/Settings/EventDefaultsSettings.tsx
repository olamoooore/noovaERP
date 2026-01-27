import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'

export function EventDefaultsSettings() {
  const { toast } = useToast()
  
  const [defaultCapacity, setDefaultCapacity] = useState('1000')
  const [defaultDuration, setDefaultDuration] = useState('4')
  const [autoApproveTickets, setAutoApproveTickets] = useState(true)
  const [requireCheckIn, setRequireCheckIn] = useState(true)
  const [enableWaitlist, setEnableWaitlist] = useState(true)
  const [maxTicketsPerOrder, setMaxTicketsPerOrder] = useState('10')
  const [refundWindow, setRefundWindow] = useState('7')
  const [cancellationPolicy, setCancellationPolicy] = useState('')
  const [termsAndConditions, setTermsAndConditions] = useState('')

  const handleSave = () => {
    toast({
      title: 'Settings saved',
      description: 'Event default settings have been updated.',
    })
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-medium">Event Defaults</h3>
        <p className="text-sm text-muted-foreground">
          Set default values and policies for new events.
        </p>
      </div>

      <div className="grid gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Event Configuration</CardTitle>
            <CardDescription>
              Default settings applied to newly created events.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="defaultCapacity">Default Capacity</Label>
                <Input 
                  id="defaultCapacity"
                  type="number"
                  value={defaultCapacity} 
                  onChange={(e) => setDefaultCapacity(e.target.value)} 
                  placeholder="1000"
                />
                <p className="text-xs text-muted-foreground">
                  Default maximum attendees for new events.
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="defaultDuration">Default Duration (hours)</Label>
                <Input 
                  id="defaultDuration"
                  type="number"
                  value={defaultDuration} 
                  onChange={(e) => setDefaultDuration(e.target.value)} 
                  placeholder="4"
                />
                <p className="text-xs text-muted-foreground">
                  Default event length in hours.
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Ticketing Defaults</CardTitle>
            <CardDescription>
              Configure default ticketing behavior and limits.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Auto-Approve Ticket Purchases</Label>
                <p className="text-sm text-muted-foreground">
                  Automatically approve ticket purchases without manual review.
                </p>
              </div>
              <Switch 
                checked={autoApproveTickets}
                onCheckedChange={setAutoApproveTickets}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Require Check-In</Label>
                <p className="text-sm text-muted-foreground">
                  Attendees must check in at the event entrance.
                </p>
              </div>
              <Switch 
                checked={requireCheckIn}
                onCheckedChange={setRequireCheckIn}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Enable Waitlist</Label>
                <p className="text-sm text-muted-foreground">
                  Allow customers to join waitlist when event is sold out.
                </p>
              </div>
              <Switch 
                checked={enableWaitlist}
                onCheckedChange={setEnableWaitlist}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="maxTicketsPerOrder">Max Tickets Per Order</Label>
              <Input 
                id="maxTicketsPerOrder"
                type="number"
                value={maxTicketsPerOrder} 
                onChange={(e) => setMaxTicketsPerOrder(e.target.value)} 
                placeholder="10"
              />
              <p className="text-xs text-muted-foreground">
                Maximum number of tickets a customer can purchase in one order.
              </p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Refund & Cancellation Policy</CardTitle>
            <CardDescription>
              Set default policies for refunds and cancellations.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="refundWindow">Refund Window (days)</Label>
              <Input 
                id="refundWindow"
                type="number"
                value={refundWindow} 
                onChange={(e) => setRefundWindow(e.target.value)} 
                placeholder="7"
              />
              <p className="text-xs text-muted-foreground">
                Number of days before event when refunds are allowed.
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="cancellationPolicy">Cancellation Policy</Label>
              <Textarea 
                id="cancellationPolicy"
                value={cancellationPolicy} 
                onChange={(e) => setCancellationPolicy(e.target.value)} 
                placeholder="Enter your cancellation policy..."
                rows={4}
              />
              <p className="text-xs text-muted-foreground">
                This will be displayed to customers during ticket purchase.
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="termsAndConditions">Terms and Conditions</Label>
              <Textarea 
                id="termsAndConditions"
                value={termsAndConditions} 
                onChange={(e) => setTermsAndConditions(e.target.value)} 
                placeholder="Enter your terms and conditions..."
                rows={4}
              />
              <p className="text-xs text-muted-foreground">
                Standard terms and conditions for event attendance.
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
