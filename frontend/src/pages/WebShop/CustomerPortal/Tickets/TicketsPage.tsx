import { useEffect, useMemo, useState } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import api from '@/lib/api'
import { useWebShopAuth } from '@/pages/WebShop/hooks/useWebShopAuth'

interface TicketRecord {
  id: number
  ticket_code: string
  status: string
  issued_at?: string
  attendee_name?: string
}

interface TicketBooking {
  id: number
  booking_reference: string
  status: string
  total_amount: number
  currency: string
  buyer_name: string
  buyer_email: string
  event_id: number
  event_title?: string
  event_date?: string
  event_venue?: string
  ticket_count: number
  tickets: TicketRecord[]
  created_at?: string
}

const formatDate = (value?: string) => {
  if (!value) return 'TBA'
  return new Date(value).toLocaleString('en-SG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const statusClassName = (status: string) => {
  switch (status.toLowerCase()) {
    case 'booked':
    case 'confirmed':
    case 'active':
      return 'bg-green-100 text-green-800 border-green-200'
    case 'pending':
      return 'bg-amber-100 text-amber-800 border-amber-200'
    case 'cancelled':
    case 'revoked':
      return 'bg-red-100 text-red-800 border-red-200'
    default:
      return 'bg-slate-100 text-slate-800 border-slate-200'
  }
}

export function TicketsPage() {
  const { getAuthHeaders } = useWebShopAuth()
  const [bookings, setBookings] = useState<TicketBooking[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchTickets = async () => {
      try {
        const response = await api.get('/web-shop/tickets', {
          headers: getAuthHeaders(),
        })

        setBookings(response.data.tickets || [])
      } catch (error) {
        console.error('Failed to fetch tickets:', error)
      } finally {
        setLoading(false)
      }
    }

    fetchTickets()
  }, [getAuthHeaders])

  const now = new Date()
  const upcomingBookings = useMemo(
    () => bookings.filter((booking) => !booking.event_date || new Date(booking.event_date) >= now),
    [bookings, now]
  )
  const pastBookings = useMemo(
    () => bookings.filter((booking) => booking.event_date && new Date(booking.event_date) < now),
    [bookings, now]
  )

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">My Tickets</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Paid event tickets completed through checkout appear here with their issued codes.
          </p>
        </div>
        <Button variant="outline" asChild>
          <a href="/store/events">Browse Events</a>
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card>
          <CardContent className="p-6 flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">Upcoming Tickets</p>
              <p className="text-3xl font-bold">{upcomingBookings.length}</p>
            </div>
            <div className="h-12 w-12 rounded-full bg-emerald-100 flex items-center justify-center">
              <Icon icon="solar:ticket-bold-duotone" className="h-6 w-6 text-emerald-600" />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6 flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">Total Issued Tickets</p>
              <p className="text-3xl font-bold">{bookings.reduce((sum, booking) => sum + booking.ticket_count, 0)}</p>
            </div>
            <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
              <Icon icon="solar:qr-code-bold-duotone" className="h-6 w-6 text-blue-600" />
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:ticket-bold-duotone" className="h-5 w-5" />
            Ticket History
          </CardTitle>
        </CardHeader>
        <CardContent>
          {bookings.length === 0 ? (
            <div className="text-center py-10 text-muted-foreground">
              <Icon icon="solar:ticket-sale-bold-duotone" className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p>No event tickets yet</p>
              <p className="text-sm mt-2">Complete checkout for a paid event and the tickets will appear here.</p>
            </div>
          ) : (
            <div className="space-y-4">
              {bookings.map((booking) => (
                <div key={booking.id} className="rounded-xl border bg-muted/30 p-4 space-y-4">
                  <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                      <div className="flex items-center gap-2 flex-wrap">
                        <h2 className="text-lg font-semibold">{booking.event_title || `Event #${booking.event_id}`}</h2>
                        <Badge variant="outline" className={statusClassName(booking.status)}>
                          {booking.status}
                        </Badge>
                      </div>
                      <p className="text-sm text-muted-foreground mt-1">
                        {booking.booking_reference} • {booking.ticket_count} ticket{booking.ticket_count === 1 ? '' : 's'}
                      </p>
                    </div>

                    <div className="text-left md:text-right">
                      <p className="font-medium">{booking.currency} {Number(booking.total_amount).toFixed(2)}</p>
                      <p className="text-sm text-muted-foreground">{formatDate(booking.event_date)}</p>
                    </div>
                  </div>

                  <div className="grid gap-3 md:grid-cols-2">
                    <div className="text-sm text-muted-foreground space-y-1">
                      <p className="flex items-center gap-2">
                        <Icon icon="solar:map-point-bold-duotone" className="h-4 w-4" />
                        <span>{booking.event_venue || 'Venue to be confirmed'}</span>
                      </p>
                      <p className="flex items-center gap-2">
                        <Icon icon="solar:user-bold-duotone" className="h-4 w-4" />
                        <span>{booking.buyer_name || booking.buyer_email}</span>
                      </p>
                    </div>

                    <div className="rounded-lg border bg-white p-3">
                      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-2">Issued Ticket Codes</p>
                      <div className="space-y-2">
                        {booking.tickets.map((ticket) => (
                          <div key={ticket.id} className="flex items-center justify-between gap-3 text-sm">
                            <div>
                              <p className="font-mono font-medium">{ticket.ticket_code}</p>
                              <p className="text-xs text-muted-foreground">{ticket.attendee_name || booking.buyer_name}</p>
                            </div>
                            <Badge variant="outline" className={statusClassName(ticket.status)}>
                              {ticket.status}
                            </Badge>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {pastBookings.length > 0 && (
        <p className="text-sm text-muted-foreground">
          {pastBookings.length} past event booking{pastBookings.length === 1 ? '' : 's'} remain visible here for reference.
        </p>
      )}
    </div>
  )
}