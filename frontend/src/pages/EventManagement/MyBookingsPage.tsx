import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Calendar, MapPin, CreditCard, Download, Eye, XCircle } from 'lucide-react';

interface Booking {
  id: number;
  reference: string;
  eventTitle: string;
  eventDate: string;
  venue: string;
  ticketCount: number;
  totalAmount: number;
  status: 'confirmed' | 'pending' | 'cancelled';
  bookedAt: string;
}

/**
 * MyBookingsPage
 * 
 * Displays all user bookings with status filtering
 */
const MyBookingsPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState('all');

  // Mock bookings data
  const bookings: Booking[] = [
    {
      id: 1,
      reference: 'BK-2026-001',
      eventTitle: 'Summer Music Festival 2026',
      eventDate: '2026-07-15',
      venue: 'Central Park',
      ticketCount: 2,
      totalAmount: 150,
      status: 'confirmed',
      bookedAt: '2026-01-15',
    },
    {
      id: 2,
      reference: 'BK-2026-002',
      eventTitle: 'Tech Conference 2026',
      eventDate: '2026-08-20',
      venue: 'Convention Center',
      ticketCount: 1,
      totalAmount: 150,
      status: 'pending',
      bookedAt: '2026-01-20',
    },
  ];

  const getStatusBadge = (status: Booking['status']) => {
    const variants = {
      confirmed: 'bg-green-100 text-green-800',
      pending: 'bg-yellow-100 text-yellow-800',
      cancelled: 'bg-red-100 text-red-800',
    };
    return <Badge className={variants[status]}>{status.toUpperCase()}</Badge>;
  };

  const filteredBookings = activeTab === 'all' 
    ? bookings 
    : bookings.filter(b => b.status === activeTab);

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">My Bookings</Heading1>
        <BodyText variant="muted">View and manage your event bookings</BodyText>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="mb-6">
        <TabsList>
          <TabsTrigger value="all">All Bookings</TabsTrigger>
          <TabsTrigger value="confirmed">Confirmed</TabsTrigger>
          <TabsTrigger value="pending">Pending</TabsTrigger>
          <TabsTrigger value="cancelled">Cancelled</TabsTrigger>
        </TabsList>

        <TabsContent value={activeTab} className="mt-6">
          {filteredBookings.length === 0 ? (
            <Card className="p-8 text-center">
              <CreditCard className="h-12 w-12 mx-auto mb-4 text-muted-foreground opacity-50" />
              <p className="text-muted-foreground">No bookings found in this category</p>
            </Card>
          ) : (
            <div className="space-y-4">
              {filteredBookings.map((booking) => (
                <Card key={booking.id} className="hover:shadow-md transition-shadow">
                  <CardContent className="p-6">
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                      {/* Booking Info */}
                      <div className="flex-1">
                        <div className="flex items-center gap-3 mb-2">
                          <h3 className="font-semibold text-lg">{booking.eventTitle}</h3>
                          {getStatusBadge(booking.status)}
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm text-muted-foreground">
                          <div className="flex items-center gap-2">
                            <Calendar className="h-4 w-4" />
                            <span>{new Date(booking.eventDate).toLocaleDateString()}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <MapPin className="h-4 w-4" />
                            <span>{booking.venue}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <CreditCard className="h-4 w-4" />
                            <span>${booking.totalAmount} ({booking.ticketCount} tickets)</span>
                          </div>
                        </div>
                        <p className="text-xs text-muted-foreground mt-2">
                          Ref: {booking.reference} • Booked: {new Date(booking.bookedAt).toLocaleDateString()}
                        </p>
                      </div>

                      {/* Actions */}
                      <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" className="flex items-center gap-2">
                          <Eye className="h-4 w-4" />
                          View
                        </Button>
                        <Button variant="outline" size="sm" className="flex items-center gap-2">
                          <Download className="h-4 w-4" />
                          Tickets
                        </Button>
                        {booking.status !== 'cancelled' && (
                          <Button variant="destructive" size="sm" className="flex items-center gap-2">
                            <XCircle className="h-4 w-4" />
                            Cancel
                          </Button>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default MyBookingsPage;
