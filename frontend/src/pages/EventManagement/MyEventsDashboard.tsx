import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { AccessibleButton } from '@/components/ui/AccessibleButton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ResponsiveText, Heading1, Heading3, BodyText } from '@/components/ui/responsive-text';
import { EnhancedCard, EnhancedCardContent, EnhancedCardHeader, EnhancedCardTitle } from '@/components/ui/enhanced-card';
import { EnhancedBadge, getSemanticColor } from '@/components/ui/EnhancedBadge';
import { AnimatedMetrics } from '@/components/ui/AnimatedMetrics';
import { 
  Calendar, 
  MapPin, 
  Clock, 
  Download, 
  Share2, 
  Ticket,
  CheckCircle,
  AlertCircle,
  Loader2,
  Plus,
  Pencil,
  Trash2
} from 'lucide-react';
import { format } from 'date-fns';
import { useCreateEvent, useCustomerDashboard, useDeleteEvent, useDownloadTicket, useEvents, useUpdateEvent } from '@/hooks/queries';
import type { Event } from '@/lib/event-management-api';
import { LoadingSpinner } from '@/components/ui/skeleton';
import { useToast } from '@/hooks/use-toast';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { formatCurrency } from '@/lib/utils';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

type EventFormState = {
  name: string;
  description: string;
  start_date: string;
  end_date: string;
  venue: string;
  capacity: string;
  status: Event['status'];
};

const makeDefaultEventForm = (): EventFormState => ({
  name: '',
  description: '',
  start_date: '',
  end_date: '',
  venue: '',
  capacity: '',
  status: 'draft',
});

const toDateTimeLocalValue = (value?: string): string => {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  const tzOffsetMs = date.getTimezoneOffset() * 60 * 1000;
  return new Date(date.getTime() - tzOffsetMs).toISOString().slice(0, 16);
};

/**
 * MyEventsDashboard Component
 * 
 * Comprehensive customer event hub with personalized dashboard,
 * event management, and recommendations.
 * 
 * Requirements: 16.1, 16.2, 16.3
 */
const MyEventsDashboard: React.FC = () => {
  // UI state only - server data now managed by React Query
  const [activeTab, setActiveTab] = useState('upcoming');
  const [isEventDialogOpen, setIsEventDialogOpen] = useState(true);
  const [editingEvent, setEditingEvent] = useState<Event | null>(null);
  const [pendingDeleteEvent, setPendingDeleteEvent] = useState<Event | null>(null);
  const [eventForm, setEventForm] = useState<EventFormState>(makeDefaultEventForm());
  const { toast } = useToast();
  
  // React Query hooks for server state
  const { 
    data: dashboardData, 
    isLoading, 
    error 
  } = useCustomerDashboard();

  const {
    data: managedEvents = [],
    isLoading: isManagedEventsLoading,
  } = useEvents();
  
  // Mutations
  const downloadTicketMutation = useDownloadTicket();
  const createEventMutation = useCreateEvent();
  const updateEventMutation = useUpdateEvent();
  const deleteEventMutation = useDeleteEvent();

  const isSavingEvent = createEventMutation.isPending || updateEventMutation.isPending;

  // Handle ticket download with React Query mutation
  const handleDownloadTicket = async (bookingId: number) => {
    try {
      await downloadTicketMutation.mutateAsync(bookingId);
      
      toast({
        title: "Success",
        description: "Ticket downloaded successfully",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to download tickets. Please try again.",
        variant: "destructive",
      });
    }
  };

  const handleShareEvent = async (eventId: number, eventTitle: string) => {
    const url = `${window.location.origin}${storefrontRoutes.events}`;

    try {
      if (navigator.share) {
        await navigator.share({
          title: eventTitle,
          text: `Check out this event: ${eventTitle}`,
          url,
        });
        toast({
          title: "Success",
          description: "Event shared successfully",
        });
      } else {
        // Fallback to clipboard
        await navigator.clipboard.writeText(url);
        toast({
          title: "Success",
          description: "Event link copied to clipboard",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to share event. Please try again.",
        variant: "destructive",
      });
    }
  };

  const openCreateEventDialog = () => {
    setEditingEvent(null);
    setEventForm(makeDefaultEventForm());
    setIsEventDialogOpen(true);
  };

  const openEditEventDialog = (event: Event) => {
    setEditingEvent(event);
    setEventForm({
      title: event.title ?? '',
      description: event.description ?? '',
      eventDate: toDateTimeLocalValue(event.start_date),
      endDate: toDateTimeLocalValue(event.end_date),
      venue: event.venue ?? '',
      totalCapacity: event.capacity != null ? String(event.capacity) : '',
      status: event.status,
    });
    setIsEventDialogOpen(true);
  };

  const handleEventFieldChange = (field: keyof EventFormState, value: string) => {
    setEventForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleSaveEvent = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!eventForm.name.trim() || !eventForm.start_date || !eventForm.end_date) {
      toast({
        title: 'Missing required fields',
        description: 'Event name, start date, and end date are required.',
        variant: 'destructive',
      });
      return;
    }

    const normalizedCapacity = eventForm.capacity.trim() ? Number(eventForm.capacity) : undefined;
    if (normalizedCapacity !== undefined && (!Number.isFinite(normalizedCapacity) || normalizedCapacity < 1)) {
      toast({
        title: 'Invalid capacity',
        description: 'Capacity must be a positive number.',
        variant: 'destructive',
      });
      return;
    }

    const payload = {
      name: eventForm.name.trim(),
      description: eventForm.description.trim() || undefined,
      start_date: new Date(eventForm.start_date).toISOString(),
      end_date: new Date(eventForm.end_date).toISOString(),
      status: eventForm.status,
      venue: eventForm.venue.trim() || undefined,
      capacity: normalizedCapacity,
    };

    try {
      if (editingEvent) {
        await updateEventMutation.mutateAsync({
          eventId: editingEvent.id,
          data: payload,
        });
        toast({
          title: 'Event updated',
          description: `${payload.name} was updated successfully.`,
        });
      } else {
        await createEventMutation.mutateAsync(payload);
        toast({
          title: 'Event created',
          description: `${payload.name} was added to your events.`,
        });
      }

      setIsEventDialogOpen(false);
      setEditingEvent(null);
      setEventForm(makeDefaultEventForm());
    } catch (error: any) {
      toast({
        title: 'Failed to save event',
        description: error?.response?.data?.message || 'Please try again.',
        variant: 'destructive',
      });
    }
  };

  const performDeleteEvent = async (event: Event) => {
    try {
      await deleteEventMutation.mutateAsync(event.id);
      toast({
        title: 'Event deleted',
        description: `${event.name} was removed.`,
      });
    } catch (error: any) {
      toast({
        title: 'Failed to delete event',
        description: error?.response?.data?.message || 'Please try again.',
        variant: 'destructive',
      });
    }
  };

  // Loading and error states
  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center py-8">
              <AlertCircle className="mx-auto h-12 w-12 text-red-400 mb-4" />
              <Heading3 className="mb-2">Failed to Load Dashboard</Heading3>
              <BodyText variant="muted" className="mb-4">
                There was an error loading your dashboard. Please try again.
              </BodyText>
              <AccessibleButton 
                onClick={() => window.location.reload()}
                ariaLabel="Retry loading dashboard"
                screenReaderText="Reload the page to retry loading your dashboard"
              >
                Try Again
              </AccessibleButton>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!dashboardData) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center py-8">
              <Calendar className="mx-auto h-12 w-12 text-gray-400 mb-4" />
              <Heading3 className="mb-2">No Data Available</Heading3>
              <BodyText variant="muted">No dashboard data is currently available.</BodyText>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">Events</Heading1>
        <BodyText variant="muted">Manage your bookings, discover new events, and track your experience</BodyText>
      </div>

      {/* Statistics Cards - Using available data */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.upcoming_events.length}
              format="number"
              label="Upcoming Events"
              icon={Calendar}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>

        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.bookings.length}
              format="number"
              label="Total Bookings"
              icon={Ticket}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>

        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.tickets.length}
              format="number"
              label="Total Tickets"
              icon={CheckCircle}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>
      </div>

      {/* Main Content Tabs - Simplified to match API */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="w-fit max-w-full">
          <TabsTrigger value="upcoming">Upcoming Events</TabsTrigger>
          <TabsTrigger value="bookings">My Bookings</TabsTrigger>
          <TabsTrigger value="manage">Manage Events</TabsTrigger>
        </TabsList>

        {/* Upcoming Events Tab */}
        <TabsContent value="upcoming" className="space-y-6">
          {dashboardData.upcoming_events.length === 0 ? (
            <EnhancedCard variant="outlined">
              <EnhancedCardContent className="pt-6">
                <div className="text-center py-8">
                  <Calendar className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <Heading3 className="mb-2">No Upcoming Events</Heading3>
                  <BodyText variant="muted" className="mb-4">No upcoming events have been booked yet.</BodyText>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.upcoming_events.map((event) => (
                <EnhancedCard key={event.id} variant="elevated" className="overflow-hidden group hover:shadow-xl transition-all duration-300">
                  <EnhancedCardContent className="p-6">
                    <EnhancedCardHeader className="px-0 pb-4">
                      <EnhancedCardTitle className="text-xl">{event.name}</EnhancedCardTitle>
                    </EnhancedCardHeader>
                    
                    <div className="space-y-3 mb-4">
                      <div className="flex items-center text-gray-600">
                        <Calendar className="h-4 w-4 mr-2 text-blue-500" />
                        <ResponsiveText size="sm" weight="medium">{format(new Date(event.start_date), 'PPP')}</ResponsiveText>
                      </div>
                      <div className="flex items-center text-gray-600">
                        <Clock className="h-4 w-4 mr-2 text-green-500" />
                        <ResponsiveText size="sm">{format(new Date(event.start_date), 'p')}</ResponsiveText>
                      </div>
                      {event.venue && (
                        <div className="flex items-center text-gray-600">
                          <MapPin className="h-4 w-4 mr-2 text-red-500" />
                          <ResponsiveText size="sm">{event.venue}</ResponsiveText>
                        </div>
                      )}
                    </div>

                    <div className="flex space-x-2">
                      <AccessibleButton 
                        size="sm" 
                        variant="outline"
                        onClick={() => handleShareEvent((event as any)._eventId ?? event.id, event.name)}
                        className="hover:bg-gray-50"
                        ariaLabel="Share event"
                        screenReaderText={`Share ${event.name} with others`}
                      >
                        <Share2 className="h-4 w-4 mr-2" />
                        Share
                      </AccessibleButton>
                    </div>
                  </EnhancedCardContent>
                </EnhancedCard>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Bookings Tab */}
        <TabsContent value="bookings" className="space-y-6">
          {dashboardData.bookings.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <div className="text-center py-8">
                  <Ticket className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Bookings Yet</h3>
                  <p className="text-gray-600">Bookings made on the storefront will appear here.</p>
                </div>
              </CardContent>
            </Card>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.bookings.map((booking) => {
                const b = booking as any;
                return (
                  <Card key={booking.id} className="overflow-hidden">
                    <CardContent className="p-6">
                      <div className="flex justify-between items-start mb-3">
                        <div className="flex-1 min-w-0 mr-3">
                          <h3 className="text-base font-semibold text-gray-900 truncate">
                            {b.event_title ?? `Event #${booking.event_id}`}
                          </h3>
                          <ResponsiveText size="sm" variant="muted">
                            Ref: {b.booking_reference ?? `#${booking.id}`}
                          </ResponsiveText>
                        </div>
                        <EnhancedBadge
                          variant="status"
                          color={getSemanticColor(booking.status, 'status')}
                          size="sm"
                        >
                          {booking.status}
                        </EnhancedBadge>
                      </div>

                      <div className="space-y-2 mb-4 text-sm">
                        {b.buyer_name && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Customer:</span>
                            <span className="font-medium">{b.buyer_name}</span>
                          </div>
                        )}
                        {b.buyer_email && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Email:</span>
                            <span className="font-medium text-xs">{b.buyer_email}</span>
                          </div>
                        )}
                        {b.event_venue && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Venue:</span>
                            <span className="font-medium">{b.event_venue}</span>
                          </div>
                        )}
                        {b.event_date && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Event Date:</span>
                            <span className="font-medium">
                              {format(new Date(b.event_date), 'PP')}
                            </span>
                          </div>
                        )}
                        <div className="flex justify-between">
                          <span className="text-gray-500">Booked:</span>
                          <span className="font-medium">
                            {booking.booking_date ? format(new Date(booking.booking_date), 'PP') : '—'}
                          </span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-500">Amount:</span>
                          <span className="font-bold">
                            {booking.total_amount === 0 ? 'FREE' : formatCurrency(Number(booking.total_amount) || 0, b.currency || undefined)}
                          </span>
                        </div>
                      </div>

                      <AccessibleButton
                        size="sm"
                        onClick={() => handleDownloadTicket(booking.id)}
                        className="w-full"
                        ariaLabel="Download tickets"
                        screenReaderText={`Download tickets for booking ${booking.id}`}
                      >
                        <Download className="h-4 w-4 mr-2" />
                        Download Ticket
                      </AccessibleButton>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          )}
        </TabsContent>

        {/* Manage Events Tab */}
        <TabsContent value="manage" className="space-y-6">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
              <Heading3 className="mb-1">My Event Management</Heading3>
              <BodyText variant="muted">Create, edit, and delete your events.</BodyText>
            </div>
            <AccessibleButton
              onClick={openCreateEventDialog}
              ariaLabel="Add event"
              screenReaderText="Open create event form"
            >
              <Plus className="h-4 w-4 mr-2" />
              Add Event
            </AccessibleButton>
          </div>

          {isManagedEventsLoading ? (
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-center py-10 text-muted-foreground">
                  <Loader2 className="h-5 w-5 mr-2 animate-spin" />
                  Loading your events...
                </div>
              </CardContent>
            </Card>
          ) : managedEvents.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <div className="text-center py-8">
                  <Calendar className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <Heading3 className="mb-2">No Events Yet</Heading3>
                  <BodyText variant="muted" className="mb-4">Start by creating your first event.</BodyText>
                  <AccessibleButton
                    onClick={openCreateEventDialog}
                    ariaLabel="Create first event"
                  >
                    Create Event
                  </AccessibleButton>
                </div>
              </CardContent>
            </Card>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {managedEvents.map((event) => (
                <Card key={event.id} className="overflow-hidden">
                  <CardContent className="p-6">
                    <div className="flex items-start justify-between mb-3">
                      <div className="min-w-0 mr-3">
                        <h3 className="text-lg font-semibold text-gray-900 truncate">{event.name}</h3>
                        <BodyText variant="muted" className="text-sm">
                          {event.venue || 'Venue TBD'}
                        </BodyText>
                      </div>
                      <EnhancedBadge variant="status" color={getSemanticColor(event.status, 'status')} size="sm">
                        {event.status}
                      </EnhancedBadge>
                    </div>

                    <div className="space-y-2 mb-5 text-sm">
                      <div className="flex justify-between">
                        <span className="text-gray-500">Start:</span>
                        <span className="font-medium">
                          {event.start_date ? format(new Date(event.start_date), 'PPp') : '—'}
                        </span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-500">End:</span>
                        <span className="font-medium">
                          {event.end_date ? format(new Date(event.end_date), 'PPp') : '—'}
                        </span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-500">Capacity:</span>
                        <span className="font-medium">{event.capacity ?? '—'}</span>
                      </div>
                    </div>

                    <div className="flex gap-2">
                      <AccessibleButton
                        size="sm"
                        variant="outline"
                        onClick={() => openEditEventDialog(event)}
                        ariaLabel={`Edit ${event.name}`}
                        className="flex-1"
                      >
                        <Pencil className="h-4 w-4 mr-2" />
                        Edit
                      </AccessibleButton>
                      <AccessibleButton
                        size="sm"
                        variant="destructive"
                        onClick={() => setPendingDeleteEvent(event)}
                        ariaLabel={`Delete ${event.name}`}
                        loading={deleteEventMutation.isPending}
                        className="flex-1"
                      >
                        <Trash2 className="h-4 w-4 mr-2" />
                        Delete
                      </AccessibleButton>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>

      <Dialog open={isEventDialogOpen} onOpenChange={setIsEventDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editingEvent ? 'Edit Event' : 'Create Event'}</DialogTitle>
            <DialogDescription>
              Fill the details below to {editingEvent ? 'update your event' : 'add a new event'}.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSaveEvent} className="space-y-4">
            <div>
              <label htmlFor="event-name" className="block text-sm font-medium mb-1">Event Name</label>
              <input
                id="event-name"
                type="text"
                value={eventForm.name}
                onChange={(e) => handleEventFieldChange('name', e.target.value)}
                className="w-full rounded-md border px-3 py-2"
                placeholder="Enter event name"
                required
              />
            </div>

            <div>
              <label htmlFor="event-description" className="block text-sm font-medium mb-1">Description</label>
              <textarea
                id="event-description"
                value={eventForm.description}
                onChange={(e) => handleEventFieldChange('description', e.target.value)}
                className="w-full rounded-md border px-3 py-2 min-h-24"
                placeholder="Add event description"
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label htmlFor="event-start" className="block text-sm font-medium mb-1">Start Date</label>
                <input
                  id="event-start"
                  type="datetime-local"
                  value={eventForm.start_date}
                  onChange={(e) => handleEventFieldChange('start_date', e.target.value)}
                  className="w-full rounded-md border px-3 py-2"
                  required
                />
              </div>

              <div>
                <label htmlFor="event-end" className="block text-sm font-medium mb-1">End Date</label>
                <input
                  id="event-end"
                  type="datetime-local"
                  value={eventForm.end_date}
                  onChange={(e) => handleEventFieldChange('end_date', e.target.value)}
                  className="w-full rounded-md border px-3 py-2"
                  required
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label htmlFor="event-venue" className="block text-sm font-medium mb-1">Venue</label>
                <input
                  id="event-venue"
                  type="text"
                  value={eventForm.venue}
                  onChange={(e) => handleEventFieldChange('venue', e.target.value)}
                  className="w-full rounded-md border px-3 py-2"
                  placeholder="Venue"
                />
              </div>

              <div>
                <label htmlFor="event-capacity" className="block text-sm font-medium mb-1">Capacity</label>
                <input
                  id="event-capacity"
                  type="number"
                  min={1}
                  value={eventForm.capacity}
                  onChange={(e) => handleEventFieldChange('capacity', e.target.value)}
                  className="w-full rounded-md border px-3 py-2"
                  placeholder="Maximum attendees"
                />
              </div>
            </div>

            <div>
              <label htmlFor="event-status" className="block text-sm font-medium mb-1">Status</label>
              <select
                id="event-status"
                value={eventForm.status}
                onChange={(e) => handleEventFieldChange('status', e.target.value)}
                className="w-full rounded-md border px-3 py-2"
              >
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>

            <DialogFooter>
              <AccessibleButton
                type="button"
                variant="outline"
                onClick={() => setIsEventDialogOpen(false)}
              >
                Cancel
              </AccessibleButton>
              <AccessibleButton
                type="submit"
                loading={isSavingEvent}
                loadingText={editingEvent ? 'Saving...' : 'Creating...'}
              >
                {editingEvent ? 'Save Changes' : 'Create Event'}
              </AccessibleButton>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
      <ConfirmDialog
        open={!!pendingDeleteEvent}
        onOpenChange={(open) => !open && setPendingDeleteEvent(null)}
        title="Delete this event?"
        description={
          <>
            Event <span className="font-medium text-foreground">{pendingDeleteEvent?.name}</span> will be
            permanently removed. This cannot be undone.
          </>
        }
        confirmLabel="Delete event"
        icon="solar:trash-bin-trash-bold-duotone"
        onConfirm={() => {
          const target = pendingDeleteEvent
          setPendingDeleteEvent(null)
          if (target) void performDeleteEvent(target)
        }}
      />
    </div>
  );
};

export default MyEventsDashboard;