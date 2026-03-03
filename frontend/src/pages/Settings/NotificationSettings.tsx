import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'

export function NotificationSettings() {
  const { toast } = useToast()
  
  // Email Notifications
  const [emailTicketPurchase, setEmailTicketPurchase] = useState(true)
  const [emailEventReminder, setEmailEventReminder] = useState(true)
  const [emailEventCancellation, setEmailEventCancellation] = useState(true)
  const [emailRefundConfirmation, setEmailRefundConfirmation] = useState(true)
  const [emailWaitlistNotification, setEmailWaitlistNotification] = useState(true)
  
  // SMS Notifications
  const [smsTicketPurchase, setSmsTicketPurchase] = useState(false)
  const [smsEventReminder, setSmsEventReminder] = useState(true)
  const [smsEventCancellation, setSmsEventCancellation] = useState(true)
  const [smsCheckInConfirmation, setSmsCheckInConfirmation] = useState(false)
  
  // Admin Notifications
  const [adminNewTicketSale, setAdminNewTicketSale] = useState(true)
  const [adminLowInventory, setAdminLowInventory] = useState(true)
  const [adminEventCapacity, setAdminEventCapacity] = useState(true)
  const [adminRefundRequest, setAdminRefundRequest] = useState(true)
  
  // Notification Settings
  const [reminderDaysBefore, setReminderDaysBefore] = useState('1')
  const [lowInventoryThreshold, setLowInventoryThreshold] = useState('10')
  const [capacityWarningPercent, setCapacityWarningPercent] = useState('90')

  const handleSave = () => {
    toast({
      title: 'Settings saved',
      description: 'Notification settings have been updated.',
    })
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-medium">Notification Settings</h3>
        <p className="text-sm text-muted-foreground">
          Configure email, SMS, and admin notification preferences.
        </p>
      </div>

      <div className="grid gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Customer Email Notifications</CardTitle>
            <CardDescription>
              Automated emails sent to customers for various events.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Ticket Purchase Confirmation</Label>
                <p className="text-sm text-muted-foreground">
                  Send confirmation email when tickets are purchased.
                </p>
              </div>
              <Switch 
                checked={emailTicketPurchase}
                onCheckedChange={setEmailTicketPurchase}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Event Reminder</Label>
                <p className="text-sm text-muted-foreground">
                  Send reminder email before the event starts.
                </p>
              </div>
              <Switch 
                checked={emailEventReminder}
                onCheckedChange={setEmailEventReminder}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Event Cancellation</Label>
                <p className="text-sm text-muted-foreground">
                  Notify customers when an event is cancelled.
                </p>
              </div>
              <Switch 
                checked={emailEventCancellation}
                onCheckedChange={setEmailEventCancellation}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Refund Confirmation</Label>
                <p className="text-sm text-muted-foreground">
                  Confirm when refunds are processed.
                </p>
              </div>
              <Switch 
                checked={emailRefundConfirmation}
                onCheckedChange={setEmailRefundConfirmation}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Waitlist Notification</Label>
                <p className="text-sm text-muted-foreground">
                  Notify when tickets become available from waitlist.
                </p>
              </div>
              <Switch 
                checked={emailWaitlistNotification}
                onCheckedChange={setEmailWaitlistNotification}
              />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Customer SMS Notifications</CardTitle>
            <CardDescription>
              Text message notifications for time-sensitive updates.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Ticket Purchase Confirmation</Label>
                <p className="text-sm text-muted-foreground">
                  Send SMS confirmation for ticket purchases.
                </p>
              </div>
              <Switch 
                checked={smsTicketPurchase}
                onCheckedChange={setSmsTicketPurchase}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Event Reminder</Label>
                <p className="text-sm text-muted-foreground">
                  Send SMS reminder before the event.
                </p>
              </div>
              <Switch 
                checked={smsEventReminder}
                onCheckedChange={setSmsEventReminder}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Event Cancellation</Label>
                <p className="text-sm text-muted-foreground">
                  Send urgent SMS for event cancellations.
                </p>
              </div>
              <Switch 
                checked={smsEventCancellation}
                onCheckedChange={setSmsEventCancellation}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Check-In Confirmation</Label>
                <p className="text-sm text-muted-foreground">
                  Confirm successful check-in via SMS.
                </p>
              </div>
              <Switch 
                checked={smsCheckInConfirmation}
                onCheckedChange={setSmsCheckInConfirmation}
              />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Admin Notifications</CardTitle>
            <CardDescription>
              Alerts for administrators and event managers.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>New Ticket Sale</Label>
                <p className="text-sm text-muted-foreground">
                  Notify when tickets are sold.
                </p>
              </div>
              <Switch 
                checked={adminNewTicketSale}
                onCheckedChange={setAdminNewTicketSale}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Low Inventory Alert</Label>
                <p className="text-sm text-muted-foreground">
                  Alert when ticket inventory is running low.
                </p>
              </div>
              <Switch 
                checked={adminLowInventory}
                onCheckedChange={setAdminLowInventory}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Event Capacity Warning</Label>
                <p className="text-sm text-muted-foreground">
                  Warn when event is nearing capacity.
                </p>
              </div>
              <Switch 
                checked={adminEventCapacity}
                onCheckedChange={setAdminEventCapacity}
              />
            </div>

            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>Refund Request</Label>
                <p className="text-sm text-muted-foreground">
                  Notify when customers request refunds.
                </p>
              </div>
              <Switch 
                checked={adminRefundRequest}
                onCheckedChange={setAdminRefundRequest}
              />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Notification Timing</CardTitle>
            <CardDescription>
              Configure when notifications are sent.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="reminderDaysBefore">Event Reminder (days before)</Label>
              <Input 
                id="reminderDaysBefore"
                type="number"
                value={reminderDaysBefore} 
                onChange={(e) => setReminderDaysBefore(e.target.value)} 
                placeholder="1"
              />
              <p className="text-xs text-muted-foreground">
                Send event reminders this many days before the event.
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="lowInventoryThreshold">Low Inventory Threshold</Label>
              <Input 
                id="lowInventoryThreshold"
                type="number"
                value={lowInventoryThreshold} 
                onChange={(e) => setLowInventoryThreshold(e.target.value)} 
                placeholder="10"
              />
              <p className="text-xs text-muted-foreground">
                Alert when remaining tickets fall below this number.
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="capacityWarningPercent">Capacity Warning (%)</Label>
              <Input 
                id="capacityWarningPercent"
                type="number"
                value={capacityWarningPercent} 
                onChange={(e) => setCapacityWarningPercent(e.target.value)} 
                placeholder="90"
              />
              <p className="text-xs text-muted-foreground">
                Warn when event reaches this percentage of capacity.
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
