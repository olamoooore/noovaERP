import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogClose,
} from '@/components/ui/dialog';
import {
  Calendar, MapPin, CreditCard, Download, Eye, XCircle, Loader2,
  User, Mail, Hash, Clock, CheckCircle2, Ticket, RefreshCcw,
} from 'lucide-react';
import { useCustomerDashboard, useCancelBooking, useRefundBooking } from '@/hooks/queries/useEventManagement';
import { useToast } from '@/hooks/use-toast';
import { format } from 'date-fns';

/* ─── Status style map ──────────────────────────────────────────────── */
const STATUS_STYLES: Record<string, { badge: string; border: string; dot: string }> = {
  confirmed: { badge: 'bg-emerald-100 text-emerald-800 border-emerald-200', border: 'border-l-emerald-500', dot: 'bg-emerald-500' },
  paid:      { badge: 'bg-emerald-100 text-emerald-800 border-emerald-200', border: 'border-l-emerald-500', dot: 'bg-emerald-500' },
  pending:   { badge: 'bg-amber-100 text-amber-800 border-amber-200',       border: 'border-l-amber-400',   dot: 'bg-amber-400'   },
  attended:  { badge: 'bg-blue-100 text-blue-800 border-blue-200',          border: 'border-l-blue-500',    dot: 'bg-blue-500'    },
  cancelled: { badge: 'bg-red-100 text-red-800 border-red-200',             border: 'border-l-red-400',     dot: 'bg-red-400'     },
};
const getStyle = (s: string) =>
  STATUS_STYLES[s] ?? { badge: 'bg-gray-100 text-gray-700 border-gray-200', border: 'border-l-gray-300', dot: 'bg-gray-400' };

function fmtDate(val?: string | null) {
  if (!val) return '—';
  try { return format(new Date(val), 'PPP'); } catch { return val; }
}
function fmtTime(val?: string | null): string | null {
  if (!val) return null;
  try { return format(new Date(val), 'p'); } catch { return null; }
}

/* ─── Detail Row ────────────────────────────────────────────────────── */
const Row: React.FC<{ icon: React.ReactNode; label: string; value: React.ReactNode }> = ({ icon, label, value }) => (
  <div className="flex items-start gap-3 py-2.5 border-b border-border/40 last:border-0">
    <span className="mt-0.5 text-muted-foreground">{icon}</span>
    <div className="flex-1 min-w-0">
      <p className="text-xs text-muted-foreground uppercase tracking-wide mb-0.5">{label}</p>
      <p className="text-sm font-medium break-all">{value}</p>
    </div>
  </div>
);

/* ─── Booking Detail Modal ──────────────────────────────────────────── */
const BookingDetailModal: React.FC<{ booking: any; open: boolean; onClose: () => void }> = ({
  booking, open, onClose,
}) => {
  if (!booking) return null;
  const status   = booking.status ?? booking.state ?? 'confirmed';
  const amount   = booking.total_amount ?? booking.amount ?? 0;
  const currency = booking.currency ?? 'SGD';
  const style    = getStyle(status);
  const eventDate = booking.event_date ?? booking.event_start;

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
      <DialogContent className="max-w-md w-full">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base">
            <Ticket className="h-4 w-4 text-primary" />
            Booking Details
          </DialogTitle>
        </DialogHeader>

        {/* Coloured header strip */}
        <div className={`rounded-lg border-l-4 ${style.border} bg-muted/40 px-4 py-3 mb-1`}>
          <div className="flex items-start justify-between gap-2 flex-wrap">
            <div>
              <p className="font-semibold text-base leading-snug">
                {booking.event_title ?? `Event #${booking.event_id}`}
              </p>
              <p className="text-xs text-muted-foreground font-mono mt-0.5">
                {booking.booking_reference ?? `#${booking.id}`}
              </p>
            </div>
            <Badge className={`${style.badge} border text-xs px-2 py-0.5 uppercase tracking-wide shrink-0`}>
              {status}
            </Badge>
          </div>
        </div>

        {/* Info rows */}
        <div>
          <Row
            icon={<Calendar className="h-4 w-4" />}
            label="Event Date"
            value={
              <span>
                {fmtDate(eventDate)}
                {fmtTime(eventDate) && (
                  <span className="ml-2 font-normal text-muted-foreground">{fmtTime(eventDate)}</span>
                )}
              </span>
            }
          />
          {booking.event_venue && (
            <Row icon={<MapPin className="h-4 w-4" />} label="Venue" value={booking.event_venue} />
          )}
          <Row icon={<User className="h-4 w-4" />}     label="Attendee"  value={booking.buyer_name  ?? '—'} />
          <Row icon={<Mail className="h-4 w-4" />}     label="Email"     value={booking.buyer_email ?? '—'} />
          <Row
            icon={<CreditCard className="h-4 w-4" />}
            label="Amount"
            value={amount === 0 ? 'FREE' : `${currency} ${Number(amount).toFixed(2)}`}
          />
          {booking.queue_number != null && (
            <Row
              icon={<Hash className="h-4 w-4" />}
              label="Ticket / Queue Number"
              value={
                <span className="font-mono font-bold text-primary text-base">
                  #{String(booking.queue_number).padStart(4, '0')}
                </span>
              }
            />
          )}
          <Row
            icon={<Hash className="h-4 w-4" />}
            label="Booking Reference"
            value={<span className="font-mono">{booking.booking_reference ?? `#${booking.id}`}</span>}
          />
          <Row
            icon={<Clock className="h-4 w-4" />}
            label="Booked On"
            value={fmtDate(booking.booking_date ?? booking.created_at)}
          />
          <Row
            icon={<CheckCircle2 className="h-4 w-4" />}
            label="Status"
            value={<span className="capitalize">{status}</span>}
          />
        </div>

        {/* Footer actions */}
        <div className="flex gap-2 pt-3">
          <Button variant="outline" size="sm" className="flex-1 gap-2" onClick={() => window.print()}>
            <Download className="h-4 w-4" /> Download / Print
          </Button>
          <DialogClose asChild>
            <Button variant="ghost" size="sm" className="flex-1">Close</Button>
          </DialogClose>
        </div>
      </DialogContent>
    </Dialog>
  );
};

/* ─── Main Page ─────────────────────────────────────────────────────── */
const MyBookingsPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState('all');
  const [viewBooking, setViewBooking] = useState<any>(null);
  const { toast } = useToast();
  const cancelMutation = useCancelBooking();
  const refundMutation = useRefundBooking();

  const { data: dashboardData, isLoading, isError } = useCustomerDashboard();
  const allBookings = (dashboardData?.bookings ?? []) as any[];

  const handleCancel = (booking: any) => {
    const reason = window.prompt(
      `Cancel booking ${booking.booking_reference ?? `#${booking.id}`}?\n\nEnter cancellation reason:`,
      'Admin cancellation'
    );
    if (!reason) return;
    cancelMutation.mutate(
      { bookingId: booking.id, reason },
      {
        onSuccess: () => toast({ title: 'Booking cancelled', description: booking.booking_reference ?? `#${booking.id}` }),
        onError: (e: any) =>
          toast({
            title: 'Cancellation failed',
            description: e?.response?.data?.error?.message ?? e?.message ?? 'Please try again',
            variant: 'destructive',
          }),
      }
    );
  };

  const handleRefund = (booking: any) => {
    const reason = window.prompt(
      `Refund booking ${booking.booking_reference ?? `#${booking.id}`}?\n\nEnter refund reason:`,
      'Customer refund requested'
    );
    if (!reason) return;
    if (!window.confirm('This will cancel the booking and mark it for refund. Continue?')) return;
    refundMutation.mutate(
      { bookingId: booking.id, reason },
      {
        onSuccess: () =>
          toast({ title: 'Refund initiated', description: booking.booking_reference ?? `#${booking.id}` }),
        onError: (e: any) =>
          toast({
            title: 'Refund failed',
            description: e?.response?.data?.error?.message ?? e?.message ?? 'Please try again',
            variant: 'destructive',
          }),
      }
    );
  };

  const filteredBookings = activeTab === 'all'
    ? allBookings
    : allBookings.filter((b) => (b.status ?? b.state) === activeTab);

  const counts = {
    confirmed: allBookings.filter((b) => ['confirmed', 'paid'].includes(b.status ?? b.state ?? '')).length,
    pending:   allBookings.filter((b) => (b.status ?? b.state) === 'pending').length,
    cancelled: allBookings.filter((b) => (b.status ?? b.state) === 'cancelled').length,
  };

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-1">Bookings</Heading1>
        <BodyText variant="muted">View customer event bookings and issued ticket IDs</BodyText>
      </div>

      {isLoading && (
        <div className="flex items-center justify-center py-24 text-muted-foreground gap-3">
          <Loader2 className="h-6 w-6 animate-spin" /> Loading bookings…
        </div>
      )}

      {isError && (
        <div className="text-center py-16 text-muted-foreground">
          <CreditCard className="h-14 w-14 mx-auto mb-4 opacity-30" />
          <p className="font-medium mb-1">Couldn't load bookings</p>
          <p className="text-sm">Please try again later.</p>
        </div>
      )}

      {!isLoading && !isError && (
        <Tabs value={activeTab} onValueChange={setActiveTab}>
          <TabsList className="mb-6">
            <TabsTrigger value="all">All ({allBookings.length})</TabsTrigger>
            <TabsTrigger value="confirmed">
              Confirmed{counts.confirmed > 0 ? ` (${counts.confirmed})` : ''}
            </TabsTrigger>
            <TabsTrigger value="pending">
              Pending{counts.pending > 0 ? ` (${counts.pending})` : ''}
            </TabsTrigger>
            <TabsTrigger value="cancelled">
              Cancelled{counts.cancelled > 0 ? ` (${counts.cancelled})` : ''}
            </TabsTrigger>
          </TabsList>

          <TabsContent value={activeTab}>
            {filteredBookings.length === 0 ? (
              <Card className="p-10 text-center">
                <Ticket className="h-12 w-12 mx-auto mb-3 text-muted-foreground opacity-30" />
                <p className="font-medium text-muted-foreground">No bookings in this category</p>
                <p className="text-sm text-muted-foreground mt-1">
                  Bookings made on the storefront will appear here.
                </p>
              </Card>
            ) : (
              <div className="space-y-3">
                {filteredBookings.map((booking) => {
                  const status    = booking.status ?? booking.state ?? 'confirmed';
                  const amount    = booking.total_amount ?? booking.amount ?? 0;
                  const currency  = booking.currency ?? 'SGD';
                  const eventDate = booking.event_date ?? booking.event_start;
                  const bookedAt  = booking.booking_date ?? booking.created_at;
                  const style     = getStyle(status);

                  return (
                    <Card
                      key={booking.id}
                      className={`border-l-4 ${style.border} hover:shadow-md transition-all duration-200`}
                    >
                      <CardContent className="p-5">
                        <div className="flex flex-col sm:flex-row sm:items-center gap-4">

                          {/* Left: booking info */}
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-1.5 flex-wrap">
                              <span className={`inline-block w-2 h-2 rounded-full flex-shrink-0 ${style.dot}`} />
                              <h3 className="font-semibold text-base leading-tight">
                                {booking.event_title ?? `Event #${booking.event_id}`}
                              </h3>
                              <Badge className={`${style.badge} border text-xs px-2 py-0 uppercase tracking-wide`}>
                                {status}
                              </Badge>
                            </div>

                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground mb-2">
                              {eventDate && (
                                <span className="flex items-center gap-1.5">
                                  <Calendar className="h-3.5 w-3.5" />
                                  {fmtDate(eventDate)}
                                </span>
                              )}
                              {booking.event_venue && (
                                <span className="flex items-center gap-1.5">
                                  <MapPin className="h-3.5 w-3.5" />
                                  {booking.event_venue}
                                </span>
                              )}
                              <span className="flex items-center gap-1.5">
                                <CreditCard className="h-3.5 w-3.5" />
                                {amount === 0 ? 'FREE' : `${currency} ${Number(amount).toFixed(2)}`}
                              </span>
                            </div>

                            <p className="text-xs text-muted-foreground">
                              <span className="font-mono">{booking.booking_reference ?? `#${booking.id}`}</span>
                              {bookedAt && <span> · Booked {fmtDate(bookedAt)}</span>}
                            </p>
                            {Array.isArray(booking.tickets) && booking.tickets.length > 0 && (
                              <p className="text-xs text-muted-foreground mt-1">
                                Ticket ID <span className="font-mono">{booking.tickets[0].ticket_code}</span>
                                {booking.tickets.length > 1 ? ` +${booking.tickets.length - 1} more` : ''}
                              </p>
                            )}
                          </div>

                          {/* Right: action buttons */}
                          <div className="flex items-center gap-2 flex-wrap sm:flex-nowrap sm:flex-col sm:items-stretch">
                            <Button
                              size="sm"
                              className="flex items-center gap-1.5 min-w-[90px]"
                              onClick={() => setViewBooking(booking)}
                            >
                              <Eye className="h-3.5 w-3.5" />
                              View
                            </Button>
                            <Button
                              variant="outline"
                              size="sm"
                              className="flex items-center gap-1.5 min-w-[90px]"
                              onClick={() => setViewBooking(booking)}
                            >
                              <Download className="h-3.5 w-3.5" />
                              Ticket
                            </Button>
                            {status !== 'cancelled' && status !== 'refunded' && (
                              <>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="flex items-center gap-1.5 min-w-[90px] text-amber-700 hover:bg-amber-50"
                                  onClick={() => handleRefund(booking)}
                                  disabled={refundMutation.isPending}
                                >
                                  <RefreshCcw className="h-3.5 w-3.5" />
                                  Refund
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="flex items-center gap-1.5 min-w-[90px] text-destructive hover:bg-destructive/10"
                                  onClick={() => handleCancel(booking)}
                                  disabled={cancelMutation.isPending}
                                >
                                  <XCircle className="h-3.5 w-3.5" />
                                  Cancel
                                </Button>
                              </>
                            )}
                          </div>
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

      {/* Detail modal */}
      <BookingDetailModal
        booking={viewBooking}
        open={!!viewBooking}
        onClose={() => setViewBooking(null)}
      />
    </div>
  );
};

export default MyBookingsPage;
