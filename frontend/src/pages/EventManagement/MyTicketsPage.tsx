import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Ticket, QrCode, Download, Calendar, MapPin, Clock, Loader2, User } from 'lucide-react';
import { useCustomerDashboard } from '@/hooks/queries/useEventManagement';
import { formatCurrency } from '@/lib/utils';
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
        <Heading1 className="text-2xl md:text-3xl mb-2">Tickets</Heading1>
        <BodyText variant="muted">View issued ticket IDs, event dates, venues, and QR details for check-in</BodyText>
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
                  const tickets   = Array.isArray(booking.tickets) ? booking.tickets : [];
                  const ref       = tickets[0]?.ticket_code ?? booking.booking_reference ?? `#${booking.id}`;

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
                              {amount === 0 ? 'FREE' : formatCurrency(Number(amount) || 0, booking.currency || undefined)}
                            </p>
                            <p className="text-xs text-muted-foreground">General Admission</p>
                            <p className="text-xs text-muted-foreground">{tickets.length} issued ticket{tickets.length === 1 ? '' : 's'}</p>
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
                {selectedTicket.tickets?.[0]?.ticket_code ?? selectedTicket.booking_reference ?? `#${selectedTicket.id}`}
              </p>
              {Array.isArray(selectedTicket.tickets) && selectedTicket.tickets.length > 1 && (
                <div className="mb-4 rounded-lg border p-3 text-left space-y-2">
                  {selectedTicket.tickets.map((ticket: any) => (
                    <div key={ticket.id} className="flex items-center justify-between gap-3 text-xs">
                      <span className="font-mono font-semibold">{ticket.ticket_code}</span>
                      <span className="text-muted-foreground">{ticket.status}</span>
                    </div>
                  ))}
                </div>
              )}
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
