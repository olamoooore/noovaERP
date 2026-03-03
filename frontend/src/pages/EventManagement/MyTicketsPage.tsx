import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Ticket, QrCode, Download, Calendar, MapPin, Clock, Loader2, User } from 'lucide-react';
import { useCustomerDashboard } from '@/hooks/queries/useEventManagement';
import { format } from 'date-fns';

/**
 * MyTicketsPage — shows all real event bookings as tickets.
 */
const MyTicketsPage: React.FC = () => {
  const [activeTab, setActiveTab]       = useState('upcoming');
  const [selectedTicket, setSelectedTicket] = useState<any | null>(null);

  const { data: dashboardData, isLoading, isError } = useCustomerDashboard();
  const now = new Date();

  const allBookings = (dashboardData?.bookings ?? []) as any[];

  const upcoming = allBookings.filter((b) => {
    const d = b.event_date ?? b.event_start;
    return !d || new Date(d) > now;
  });

  const past = allBookings.filter((b) => {
    const d = b.event_date ?? b.event_start;
    return d && new Date(d) <= now;
  });

  const displayed = activeTab === 'upcoming' ? upcoming : past;

  const getStatusBadge = (status: string) => {
    const map: Record<string, { className: string; label: string }> = {
      confirmed: { className: 'bg-green-100 text-green-800', label: 'CONFIRMED' },
      paid:      { className: 'bg-green-100 text-green-800', label: 'PAID' },
      attended:  { className: 'bg-blue-100 text-blue-800',  label: 'ATTENDED' },
      pending:   { className: 'bg-yellow-100 text-yellow-800', label: 'PENDING' },
      cancelled: { className: 'bg-red-100 text-red-800',    label: 'CANCELLED' },
    };
    const cfg = map[status] ?? { className: 'bg-gray-100 text-gray-700', label: status.toUpperCase() };
    return <Badge className={cfg.className}>{cfg.label}</Badge>;
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">My Tickets</Heading1>
        <BodyText variant="muted">View your tickets and access QR codes for check-in</BodyText>
      </div>

      {isLoading && (
        <div className="flex items-center justify-center py-24 text-muted-foreground">
          <Loader2 className="h-8 w-8 animate-spin mr-3" /> Loading tickets…
        </div>
      )}

      {isError && (
        <div className="text-center py-16 text-muted-foreground">
          <Ticket className="h-16 w-16 mx-auto mb-4 opacity-40" />
          <p>Couldn't load tickets. Please try again.</p>
        </div>
      )}

      {!isLoading && !isError && (
        <Tabs value={activeTab} onValueChange={setActiveTab} className="mb-6">
          <TabsList>
            <TabsTrigger value="upcoming">Upcoming ({upcoming.length})</TabsTrigger>
            <TabsTrigger value="past">Past Events ({past.length})</TabsTrigger>
          </TabsList>

          <TabsContent value={activeTab} className="mt-6">
            {displayed.length === 0 ? (
              <Card className="p-8 text-center">
                <Ticket className="h-12 w-12 mx-auto mb-4 text-muted-foreground opacity-50" />
                <p className="text-muted-foreground">
                  {activeTab === 'upcoming' ? 'No upcoming tickets' : 'No past tickets'}
                </p>
              </Card>
            ) : (
              <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {displayed.map((booking) => {
                  const status    = booking.status ?? booking.state ?? 'confirmed';
                  const eventDate = booking.event_date ?? booking.event_start;
                  const amount    = booking.total_amount ?? booking.amount ?? 0;
                  const currency  = booking.currency ?? 'SGD';
                  const ref       = booking.booking_reference ?? `#${booking.id}`;

                  return (
                    <Card
                      key={booking.id}
                      className="hover:shadow-md transition-shadow cursor-pointer"
                      onClick={() => setSelectedTicket(booking)}
                    >
                      <CardContent className="p-6">
                        <div className="flex items-start justify-between mb-4">
                          <div className="flex items-center gap-2">
                            <Ticket className="h-5 w-5 text-primary flex-shrink-0" />
                            <span className="font-mono text-xs break-all">{ref}</span>
                          </div>
                          {getStatusBadge(status)}
                        </div>

                        <h3 className="font-semibold text-lg mb-3 leading-tight">
                          {booking.event_title ?? `Event #${booking.event_id}`}
                        </h3>

                        <div className="space-y-2 text-sm text-muted-foreground mb-4">
                          {eventDate && (
                            <>
                              <div className="flex items-center gap-2">
                                <Calendar className="h-4 w-4 flex-shrink-0" />
                                <span>{format(new Date(eventDate), 'PP')}</span>
                              </div>
                              <div className="flex items-center gap-2">
                                <Clock className="h-4 w-4 flex-shrink-0" />
                                <span>{format(new Date(eventDate), 'p')}</span>
                              </div>
                            </>
                          )}
                          {booking.event_venue && (
                            <div className="flex items-center gap-2">
                              <MapPin className="h-4 w-4 flex-shrink-0" />
                              <span>{booking.event_venue}</span>
                            </div>
                          )}
                          {booking.buyer_name && (
                            <div className="flex items-center gap-2">
                              <User className="h-4 w-4 flex-shrink-0" />
                              <span>{booking.buyer_name}</span>
                            </div>
                          )}
                        </div>

                        <div className="flex items-center justify-between pt-4 border-t">
                          <div>
                            <p className="font-medium">
                              {amount === 0 ? 'FREE' : `${currency} ${Number(amount).toFixed(2)}`}
                            </p>
                            <p className="text-xs text-muted-foreground">General Admission</p>
                          </div>
                          <Button size="sm" variant="outline" className="flex items-center gap-2">
                            <QrCode className="h-4 w-4" />
                            View QR
                          </Button>
                        </div>
                      </CardContent>
                    </Card>
                  );
                })}
              </div>
            )}
          </TabsContent>
        </Tabs>
      )}

      {/* QR Code / Ticket Detail Modal */}
      {selectedTicket && (
        <div
          className="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
          onClick={() => setSelectedTicket(null)}
        >
          <Card className="max-w-sm w-full mx-4" onClick={(e) => e.stopPropagation()}>
            <CardContent className="p-6 text-center">
              <h3 className="font-semibold text-lg mb-1">
                {selectedTicket.event_title ?? `Event #${selectedTicket.event_id}`}
              </h3>
              {selectedTicket.buyer_name && (
                <p className="text-sm text-muted-foreground mb-1">{selectedTicket.buyer_name}</p>
              )}
              <p className="text-xs text-muted-foreground mb-4">{selectedTicket.buyer_email}</p>

              {/* QR placeholder */}
              <div className="w-48 h-48 mx-auto bg-gray-100 rounded-lg flex items-center justify-center mb-4">
                <QrCode className="h-24 w-24 text-gray-400" />
              </div>

              <p className="font-mono text-sm mb-1 font-bold">
                {selectedTicket.booking_reference ?? `#${selectedTicket.id}`}
              </p>
              {selectedTicket.event_date && (
                <p className="text-xs text-muted-foreground mb-4">
                  {format(new Date(selectedTicket.event_date), 'PPP p')}
                </p>
              )}

              <div className="flex gap-2">
                <Button className="flex-1 flex items-center justify-center gap-2">
                  <Download className="h-4 w-4" />
                  Download
                </Button>
                <Button
                  variant="outline"
                  className="flex-1"
                  onClick={() => setSelectedTicket(null)}
                >
                  Close
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
};

export default MyTicketsPage;

interface TicketData {
  id: number;
  ticketNumber: string;
  eventTitle: string;
  eventDate: string;
  venue: string;
  ticketType: string;
  seat?: string;
  status: 'valid' | 'used' | 'expired';
  qrCode: string;
}

/**
 * MyTicketsPage
 * 
 * Displays all user tickets with QR codes for check-in
 */
const MyTicketsPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState('upcoming');
  const [selectedTicket, setSelectedTicket] = useState<TicketData | null>(null);

  // Mock tickets data
  const tickets: TicketData[] = [
    {
      id: 1,
      ticketNumber: 'TKT-2026-00001',
      eventTitle: 'Summer Music Festival 2026',
      eventDate: '2026-07-15T18:00:00',
      venue: 'Central Park, Main Stage',
      ticketType: 'VIP Pass',
      seat: 'VIP Section A',
      status: 'valid',
      qrCode: 'data:image/svg+xml,...', // Placeholder
    },
    {
      id: 2,
      ticketNumber: 'TKT-2026-00002',
      eventTitle: 'Summer Music Festival 2026',
      eventDate: '2026-07-15T18:00:00',
      venue: 'Central Park, Main Stage',
      ticketType: 'General Admission',
      status: 'valid',
      qrCode: 'data:image/svg+xml,...',
    },
    {
      id: 3,
      ticketNumber: 'TKT-2026-00003',
      eventTitle: 'Tech Conference 2026',
      eventDate: '2026-08-20T09:00:00',
      venue: 'Convention Center, Hall B',
      ticketType: 'Early Bird',
      seat: 'Row 5, Seat 12',
      status: 'valid',
      qrCode: 'data:image/svg+xml,...',
    },
  ];

  const getStatusBadge = (status: TicketData['status']) => {
    const config = {
      valid: { className: 'bg-green-100 text-green-800', label: 'VALID' },
      used: { className: 'bg-blue-100 text-blue-800', label: 'USED' },
      expired: { className: 'bg-gray-100 text-gray-800', label: 'EXPIRED' },
    };
    return <Badge className={config[status].className}>{config[status].label}</Badge>;
  };

  const filteredTickets = activeTab === 'upcoming'
    ? tickets.filter(t => t.status === 'valid')
    : tickets.filter(t => t.status !== 'valid');

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">My Tickets</Heading1>
        <BodyText variant="muted">View your tickets and access QR codes for check-in</BodyText>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="mb-6">
        <TabsList>
          <TabsTrigger value="upcoming">Upcoming</TabsTrigger>
          <TabsTrigger value="past">Past Events</TabsTrigger>
        </TabsList>

        <TabsContent value={activeTab} className="mt-6">
          {filteredTickets.length === 0 ? (
            <Card className="p-8 text-center">
              <Ticket className="h-12 w-12 mx-auto mb-4 text-muted-foreground opacity-50" />
              <p className="text-muted-foreground">
                {activeTab === 'upcoming' ? 'No upcoming tickets' : 'No past tickets'}
              </p>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {filteredTickets.map((ticket) => (
                <Card 
                  key={ticket.id} 
                  className="hover:shadow-md transition-shadow cursor-pointer"
                  onClick={() => setSelectedTicket(ticket)}
                >
                  <CardContent className="p-6">
                    {/* Ticket Header */}
                    <div className="flex items-start justify-between mb-4">
                      <div className="flex items-center gap-2">
                        <Ticket className="h-5 w-5 text-primary" />
                        <span className="font-mono text-sm">{ticket.ticketNumber}</span>
                      </div>
                      {getStatusBadge(ticket.status)}
                    </div>

                    {/* Event Info */}
                    <h3 className="font-semibold text-lg mb-3">{ticket.eventTitle}</h3>
                    
                    <div className="space-y-2 text-sm text-muted-foreground mb-4">
                      <div className="flex items-center gap-2">
                        <Calendar className="h-4 w-4" />
                        <span>{new Date(ticket.eventDate).toLocaleDateString()}</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <Clock className="h-4 w-4" />
                        <span>{new Date(ticket.eventDate).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <MapPin className="h-4 w-4" />
                        <span>{ticket.venue}</span>
                      </div>
                    </div>

                    {/* Ticket Type */}
                    <div className="flex items-center justify-between pt-4 border-t">
                      <div>
                        <p className="font-medium">{ticket.ticketType}</p>
                        {ticket.seat && <p className="text-xs text-muted-foreground">{ticket.seat}</p>}
                      </div>
                      <Button size="sm" variant="outline" className="flex items-center gap-2">
                        <QrCode className="h-4 w-4" />
                        View QR
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* QR Code Modal (simplified) */}
      {selectedTicket && (
        <div 
          className="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
          onClick={() => setSelectedTicket(null)}
        >
          <Card className="max-w-sm w-full mx-4" onClick={(e) => e.stopPropagation()}>
            <CardContent className="p-6 text-center">
              <h3 className="font-semibold text-lg mb-2">{selectedTicket.eventTitle}</h3>
              <p className="text-sm text-muted-foreground mb-4">{selectedTicket.ticketType}</p>
              
              {/* QR Code Placeholder */}
              <div className="w-48 h-48 mx-auto bg-gray-100 rounded-lg flex items-center justify-center mb-4">
                <QrCode className="h-24 w-24 text-gray-400" />
              </div>
              
              <p className="font-mono text-sm mb-4">{selectedTicket.ticketNumber}</p>
              
              <div className="flex gap-2">
                <Button className="flex-1 flex items-center justify-center gap-2">
                  <Download className="h-4 w-4" />
                  Download
                </Button>
                <Button 
                  variant="outline" 
                  className="flex-1"
                  onClick={() => setSelectedTicket(null)}
                >
                  Close
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
};

export default MyTicketsPage;
