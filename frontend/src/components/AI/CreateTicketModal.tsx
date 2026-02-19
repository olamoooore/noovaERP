import { useState } from 'react'
import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

interface CreateTicketModalProps {
  open: boolean
  onClose: () => void
  conversationId: number
  customerId: number
  onTicketCreated?: (ticketNumber: string) => void
}

export function CreateTicketModal({
  open,
  onClose,
  conversationId,
  customerId,
  onTicketCreated,
}: CreateTicketModalProps) {
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [priority, setPriority] = useState<'low' | 'medium' | 'high' | 'urgent'>('medium')
  const [category, setCategory] = useState('other')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const { toast } = useToast()

  const handleSubmit = async () => {
    if (!subject.trim() || !description.trim()) {
      toast({
        title: 'Validation Error',
        description: 'Please fill in both subject and description',
        variant: 'destructive',
      })
      return
    }

    setIsSubmitting(true)
    try {
      // Call AI chat endpoint with ticket creation request
      const response = await api.post('/ai/chat', {
        customer_id: customerId,
        conversation_id: conversationId,
        message: `Create a support ticket with subject: "${subject}", description: "${description}", priority: ${priority}, category: ${category}`,
        channel: 'web',
      })

      if (response.data.success) {
        // Extract ticket number from AI response
        const ticketMatch = response.data.message.match(/TKT-\d{8}-\d{4}/)
        const ticketNumber = ticketMatch ? ticketMatch[0] : 'Unknown'

        toast({
          title: 'Ticket Created',
          description: `Support ticket ${ticketNumber} has been created successfully`,
        })

        if (onTicketCreated) {
          onTicketCreated(ticketNumber)
        }

        // Reset form
        setSubject('')
        setDescription('')
        setPriority('medium')
        setCategory('other')
        
        onClose()
      } else {
        throw new Error(response.data.error || 'Failed to create ticket')
      }
    } catch (error: any) {
      console.error('Failed to create ticket:', error)
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create support ticket',
        variant: 'destructive',
      })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="mdi:ticket" className="h-5 w-5" />
            Create Support Ticket
          </DialogTitle>
          <DialogDescription>
            Our support team will contact you within 2 hours
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-4">
          {/* Subject */}
          <div className="space-y-2">
            <Label htmlFor="subject">Subject *</Label>
            <Input
              id="subject"
              placeholder="Brief summary of your issue"
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              maxLength={255}
              disabled={isSubmitting}
            />
            <p className="text-xs text-muted-foreground">
              {subject.length}/255 characters
            </p>
          </div>

          {/* Description */}
          <div className="space-y-2">
            <Label htmlFor="description">Description *</Label>
            <Textarea
              id="description"
              placeholder="Detailed description of your problem"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={4}
              disabled={isSubmitting}
            />
          </div>

          {/* Priority */}
          <div className="space-y-2">
            <Label htmlFor="priority">Priority</Label>
            <Select
              value={priority}
              onValueChange={(value: any) => setPriority(value)}
              disabled={isSubmitting}
            >
              <SelectTrigger id="priority">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="low">
                  <div className="flex items-center gap-2">
                    <Icon icon="mdi:arrow-down" className="h-4 w-4 text-blue-500" />
                    Low
                  </div>
                </SelectItem>
                <SelectItem value="medium">
                  <div className="flex items-center gap-2">
                    <Icon icon="mdi:minus" className="h-4 w-4 text-yellow-500" />
                    Medium
                  </div>
                </SelectItem>
                <SelectItem value="high">
                  <div className="flex items-center gap-2">
                    <Icon icon="mdi:arrow-up" className="h-4 w-4 text-orange-500" />
                    High
                  </div>
                </SelectItem>
                <SelectItem value="urgent">
                  <div className="flex items-center gap-2">
                    <Icon icon="mdi:alert" className="h-4 w-4 text-red-500" />
                    Urgent
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Category */}
          <div className="space-y-2">
            <Label htmlFor="category">Category</Label>
            <Select
              value={category}
              onValueChange={setCategory}
              disabled={isSubmitting}
            >
              <SelectTrigger id="category">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="orders">📦 Orders & Shipping</SelectItem>
                <SelectItem value="loyalty">🎁 Loyalty & Rewards</SelectItem>
                <SelectItem value="products">🛍️ Products & Catalog</SelectItem>
                <SelectItem value="invoices">💰 Invoices & Payments</SelectItem>
                <SelectItem value="account">👤 Account & Settings</SelectItem>
                <SelectItem value="other">❓ Other</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={onClose}
            disabled={isSubmitting}
          >
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isSubmitting || !subject.trim() || !description.trim()}
          >
            {isSubmitting ? (
              <>
                <Icon icon="mdi:loading" className="h-4 w-4 mr-2 animate-spin" />
                Creating...
              </>
            ) : (
              <>
                <Icon icon="mdi:ticket" className="h-4 w-4 mr-2" />
                Create Ticket
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
