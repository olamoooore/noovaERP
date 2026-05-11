import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { format } from 'date-fns';
import {
  AlertCircle,
  ArrowRight,
  Calendar,
  Clock,
  Download,
  Globe2,
  Loader2,
  MapPin,
  Pencil,
  Plus,
  Search,
  Share2,
  Sparkles,
  Ticket,
  Trash2,
  Users,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AccessibleButton, buttonVariants } from '@/components/ui/AccessibleButton';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Heading1, Heading3, BodyText } from '@/components/ui/responsive-text';
import { EnhancedBadge, getSemanticColor } from '@/components/ui/EnhancedBadge';
import { LoadingSpinner } from '@/components/ui/skeleton';
import { useToast } from '@/hooks/use-toast';
import { useCreateEvent, useCustomerDashboard, useDeleteEvent, useDownloadTicket, useEvents, useUpdateEvent } from '@/hooks/queries';
import { cn } from '@/lib/utils';
import { fetchPublicEvents, type ApiEvent } from '@/storefront/lib/api';
import { useProducts } from '@/storefront/hooks/useProducts';

type EventFormState = {
  title: string;
  description: string;
  venue: string;
  eventDate: string;
  endDate: string;
  totalCapacity: string;
  saleStartDate: string;
  saleEndDate: string;
  termsConditions: string;
  ticketAcquisitionMode: 'buy' | 'bid';
  merchandiseProductIds: string;
  bundleProductIds: string;
};

type UnifiedEvent = {
  id: number;
  title: string;
  description: string;
  venue: string;
  startDate: string;
  endDate: string;
  status: string;
  totalCapacity: number | null;
  availableCapacity: number | null;
  price: number | null;
  inPublicCatalog: boolean;
  saleStatus: string | null;
  acquisitionMode: string | null;
  merchandiseProductIds: number[];
  bundleProductIds: number[];
};

const parseProductIdList = (value: string): number[] => {
  return value
    .split(',')
    .map((item) => Number(item.trim()))
    .filter((item) => Number.isInteger(item) && item > 0);
};

const stringifyProductIdList = (values: number[]): string => {
  return Array.from(new Set(values))
    .sort((left, right) => left - right)
    .join(', ');
};

const toDateTimeLocalValue = (value?: string): string => {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  const tzOffsetMs = date.getTimezoneOffset() * 60 * 1000;
  return new Date(date.getTime() - tzOffsetMs).toISOString().slice(0, 16);
};

const buildDefaultForm = (): EventFormState => {
  const now = new Date();
  const eventDate = new Date(now.getTime() + 14 * 24 * 60 * 60 * 1000);
  const endDate = new Date(eventDate.getTime() + 2 * 60 * 60 * 1000);
  const saleStartDate = new Date(now.getTime() + 24 * 60 * 60 * 1000);
  const saleEndDate = new Date(eventDate.getTime() - 24 * 60 * 60 * 1000);

  return {
    title: '',
    description: '',
    venue: '',
    eventDate: toDateTimeLocalValue(eventDate.toISOString()),
    endDate: toDateTimeLocalValue(endDate.toISOString()),
    totalCapacity: '100',
    saleStartDate: toDateTimeLocalValue(saleStartDate.toISOString()),
    saleEndDate: toDateTimeLocalValue(saleEndDate.toISOString()),
    termsConditions: '',
    ticketAcquisitionMode: 'buy',
    merchandiseProductIds: '',
    bundleProductIds: '',
  };
};

const formatEventDate = (value?: string): string => {
  if (!value) return 'Date TBD';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'Date TBD';
  return format(date, 'EEE, d MMM yyyy, p');
};

const normalizeManagedEvent = (event: any): UnifiedEvent => ({
  id: Number(event.id),
  title: event.title || event.name || 'Untitled Event',
  description: event.description || '',
  venue: event.venue || 'Venue TBD',
  startDate: event.start_date || event.event_date || '',
  endDate: event.end_date || event.start_date || event.event_date || '',
  status: event.status || 'draft',
  totalCapacity: event.inventory?.total ?? event.total_capacity ?? event.capacity ?? null,
  availableCapacity: event.inventory?.remaining ?? event.available_capacity ?? null,
  price: typeof event.price === 'number' ? event.price : null,
  inPublicCatalog: event.status === 'published' || event.sale_status === 'open',
  saleStatus: event.sale_status || null,
  acquisitionMode: event.ticket_acquisition_mode || null,
  merchandiseProductIds: Array.isArray(event.merchandise_products) ? event.merchandise_products.map((product: any) => Number(product.id)).filter(Boolean) : [],
  bundleProductIds: Array.isArray(event.bundle_products) ? event.bundle_products.map((product: any) => Number(product.id)).filter(Boolean) : [],
});

const normalizePublicEvent = (event: ApiEvent): UnifiedEvent => ({
  id: Number(event.id),
  title: event.title || 'Untitled Event',
  description: event.description || '',
  venue: event.venue || 'Venue TBD',
  startDate: event.start_date || event.event_date || '',
  endDate: event.end_date || event.start_date || event.event_date || '',
  status: event.status || 'published',
  totalCapacity: event.total_capacity ?? event.total_spots ?? null,
  availableCapacity: event.available_capacity ?? event.spots_left ?? null,
  price: typeof event.price === 'number' ? event.price : null,
  inPublicCatalog: true,
  saleStatus: null,
  acquisitionMode: null,
  merchandiseProductIds: (event.merchandise_products || []).map((product) => Number(product.id)).filter(Boolean),
  bundleProductIds: (event.bundle_products || []).map((product) => Number(product.id)).filter(Boolean),
});

const EventsWorkspacePage: React.FC = () => {
  const [activeTab, setActiveTab] = useState('manage');
  const [manageSearch, setManageSearch] = useState('');
  const [manageFilter, setManageFilter] = useState<'all' | 'live' | 'draft' | 'published' | 'active'>('all');
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingEvent, setEditingEvent] = useState<UnifiedEvent | null>(null);
  const [eventForm, setEventForm] = useState<EventFormState>(buildDefaultForm());
  const [deletingEventId, setDeletingEventId] = useState<number | null>(null);
  const { toast } = useToast();

  const { data: dashboardData, isLoading, error } = useCustomerDashboard();
  const { data: managedEvents = [], isLoading: isManagedEventsLoading } = useEvents();
  const { data: publicCatalogEvents = [], isLoading: isPublicCatalogLoading } = useQuery({
    queryKey: ['event-management-public-catalog'],
    queryFn: fetchPublicEvents,
    staleTime: 60 * 1000,
  });
  const { data: merchandiseProductsData, isLoading: isMerchandiseProductsLoading } = useProducts({ per_page: 100 });
  const { data: bundleProductsData, isLoading: isBundleProductsLoading } = useProducts({ per_page: 100, is_bundle: true });

  const downloadTicketMutation = useDownloadTicket();
  const createEventMutation = useCreateEvent();
  const updateEventMutation = useUpdateEvent();
  const deleteEventMutation = useDeleteEvent();
  const isSavingEvent = createEventMutation.isPending || updateEventMutation.isPending;

  const mergedEvents = useMemo(() => {
    const eventMap = new Map<number, UnifiedEvent>();

    publicCatalogEvents.forEach((event) => {
      eventMap.set(event.id, normalizePublicEvent(event));
    });

    managedEvents.forEach((event: any) => {
      const normalized = normalizeManagedEvent(event);
      const existing = eventMap.get(normalized.id);
      eventMap.set(normalized.id, {
        ...existing,
        ...normalized,
        inPublicCatalog: existing?.inPublicCatalog || normalized.inPublicCatalog,
        merchandiseProductIds: normalized.merchandiseProductIds.length > 0 ? normalized.merchandiseProductIds : existing?.merchandiseProductIds || [],
        bundleProductIds: normalized.bundleProductIds.length > 0 ? normalized.bundleProductIds : existing?.bundleProductIds || [],
      });
    });

    return Array.from(eventMap.values()).sort((left, right) => {
      const leftTime = left.startDate ? new Date(left.startDate).getTime() : 0;
      const rightTime = right.startDate ? new Date(right.startDate).getTime() : 0;
      return leftTime - rightTime;
    });
  }, [managedEvents, publicCatalogEvents]);

  const filteredEvents = useMemo(() => {
    return mergedEvents.filter((event) => {
      const matchesSearch = !manageSearch.trim()
        || [event.title, event.venue, event.description].join(' ').toLowerCase().includes(manageSearch.toLowerCase());

      const matchesFilter = manageFilter === 'all'
        ? true
        : manageFilter === 'live'
          ? event.inPublicCatalog
          : event.status === manageFilter;

      return matchesSearch && matchesFilter;
    });
  }, [manageFilter, manageSearch, mergedEvents]);

  const stats = useMemo(() => ({
    managedCount: mergedEvents.length,
    liveCount: mergedEvents.filter((event) => event.inPublicCatalog).length,
    bookingCount: dashboardData?.bookings.length ?? 0,
    ticketCount: dashboardData?.tickets.length ?? 0,
  }), [dashboardData, mergedEvents]);

  const merchandiseProductOptions = useMemo(
    () => (merchandiseProductsData?.products || []).filter((product) => !product.isBundle),
    [merchandiseProductsData?.products],
  );

  const bundleProductOptions = useMemo(
    () => bundleProductsData?.products || [],
    [bundleProductsData?.products],
  );

  const openCreateDialog = () => {
    setEditingEvent(null);
    setEventForm(buildDefaultForm());
    setIsDialogOpen(true);
  };

  const openEditDialog = (event: UnifiedEvent) => {
    setEditingEvent(event);
    setEventForm({
      title: event.title,
      description: event.description,
      venue: event.venue,
      eventDate: toDateTimeLocalValue(event.startDate),
      endDate: toDateTimeLocalValue(event.endDate || event.startDate),
      totalCapacity: event.totalCapacity != null ? String(event.totalCapacity) : '100',
      saleStartDate: toDateTimeLocalValue(event.startDate),
      saleEndDate: toDateTimeLocalValue(event.endDate || event.startDate),
      termsConditions: '',
      ticketAcquisitionMode: event.acquisitionMode === 'bid' ? 'bid' : 'buy',
      merchandiseProductIds: event.merchandiseProductIds.join(', '),
      bundleProductIds: event.bundleProductIds.join(', '),
    });
    setIsDialogOpen(true);
  };

  const handleFormChange = (field: keyof EventFormState, value: string) => {
    setEventForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleLinkedProductSelection = (
    field: 'merchandiseProductIds' | 'bundleProductIds',
    values: number[],
  ) => {
    handleFormChange(field, stringifyProductIdList(values));
  };

  const handleDownloadTicket = async (bookingId: number) => {
    try {
      await downloadTicketMutation.mutateAsync(bookingId);
      toast({ title: 'Success', description: 'Ticket downloaded successfully' });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to download tickets. Please try again.',
        variant: 'destructive',
      });
    }
  };

  const handleShareEvent = async (eventTitle: string) => {
    const url = `${window.location.origin}/event-management/browse`;

    try {
      if (navigator.share) {
        await navigator.share({
          title: eventTitle,
          text: `Check out this event: ${eventTitle}`,
          url,
        });
      } else {
        await navigator.clipboard.writeText(url);
      }

      toast({ title: 'Success', description: 'Event shared successfully' });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to share event. Please try again.',
        variant: 'destructive',
      });
    }
  };

  const handleSaveEvent = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!eventForm.title.trim() || !eventForm.eventDate || !eventForm.venue.trim()) {
      toast({
        title: 'Missing required fields',
        description: 'Title, event date, and venue are required.',
        variant: 'destructive',
      });
      return;
    }

    const totalCapacity = Number(eventForm.totalCapacity);
    if (!Number.isFinite(totalCapacity) || totalCapacity < 1) {
      toast({
        title: 'Invalid capacity',
        description: 'Total capacity must be at least 1.',
        variant: 'destructive',
      });
      return;
    }

    try {
      const merchandiseProductIds = parseProductIdList(eventForm.merchandiseProductIds);
      const bundleProductIds = parseProductIdList(eventForm.bundleProductIds);

      if (editingEvent) {
        await updateEventMutation.mutateAsync({
          eventId: editingEvent.id,
          data: {
            title: eventForm.title.trim(),
            description: eventForm.description.trim() || undefined,
            event_date: new Date(eventForm.eventDate).toISOString(),
            venue: eventForm.venue.trim(),
            terms_conditions: eventForm.termsConditions.trim() || undefined,
            merchandise_product_ids: merchandiseProductIds,
            bundle_product_ids: bundleProductIds,
          } as any,
        });

        toast({
          title: 'Event updated',
          description: `${eventForm.title.trim()} was updated successfully.`,
        });
      } else {
        await createEventMutation.mutateAsync({
          title: eventForm.title.trim(),
          description: eventForm.description.trim() || undefined,
          event_date: new Date(eventForm.eventDate).toISOString(),
          start_date: new Date(eventForm.eventDate).toISOString(),
          end_date: new Date(eventForm.endDate || eventForm.eventDate).toISOString(),
          venue: eventForm.venue.trim(),
          total_capacity: totalCapacity,
          terms_conditions: eventForm.termsConditions.trim() || undefined,
          event_type_id: 1,
          event_type: 'concert',
          ticket_acquisition_mode: eventForm.ticketAcquisitionMode,
          sale_start_date: new Date(eventForm.saleStartDate || eventForm.eventDate).toISOString(),
          sale_end_date: new Date(eventForm.saleEndDate || eventForm.eventDate).toISOString(),
          merchandise_product_ids: merchandiseProductIds,
          bundle_product_ids: bundleProductIds,
        } as any);

        toast({
          title: 'Event created',
          description: `${eventForm.title.trim()} was added to your event board.`,
        });
      }

      setIsDialogOpen(false);
      setEditingEvent(null);
      setEventForm(buildDefaultForm());
    } catch (saveError: any) {
      const message = saveError?.response?.data?.message
        || saveError?.response?.data?.error?.message
        || 'Please review the form and try again.';

      toast({
        title: 'Failed to save event',
        description: message,
        variant: 'destructive',
      });
    }
  };

  const handleDeleteEvent = async (event: UnifiedEvent) => {
    const confirmed = window.confirm(`Delete event "${event.title}"? This action cannot be undone.`);
    if (!confirmed) return;

    setDeletingEventId(event.id);
    try {
      await deleteEventMutation.mutateAsync(event.id);
      toast({ title: 'Event deleted', description: `${event.title} was removed.` });
    } catch (deleteError: any) {
      toast({
        title: 'Failed to delete event',
        description: deleteError?.response?.data?.message || 'Please try again.',
        variant: 'destructive',
      });
    } finally {
      setDeletingEventId(null);
    }
  };

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
                There was an error loading your event workspace. Please try again.
              </BodyText>
              <AccessibleButton onClick={() => window.location.reload()}>
                Reload Workspace
              </AccessibleButton>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!dashboardData) {
    return null;
  }

  return (
    <div className="min-h-screen bg-stone-50">
      <div className="container mx-auto px-4 py-8 space-y-8">
        <section className="rounded-3xl border border-stone-200 bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.22),_transparent_32%),linear-gradient(135deg,_#faf7f2,_#ffffff_55%,_#ecfeff)] p-6 md:p-8 shadow-sm">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-3xl space-y-4">
              <div className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-white/80 px-3 py-1 text-sm font-medium text-stone-700">
                <Sparkles className="h-4 w-4 text-amber-500" />
                Event Control Room
              </div>
              <div>
                <Heading1 className="text-3xl md:text-4xl text-stone-900 mb-2">My Events</Heading1>
                <BodyText className="max-w-2xl text-stone-600">
                  One place to manage your live catalog, unpublished drafts, customer bookings, and ticket delivery without bouncing between disconnected pages.
                </BodyText>
              </div>
            </div>

            <div className="flex flex-col sm:flex-row gap-3">
              <AccessibleButton onClick={openCreateDialog}>
                <Plus className="h-4 w-4 mr-2" />
                Create Event
              </AccessibleButton>
              <Link
                to="/event-management/browse"
                className={cn(buttonVariants({ variant: 'outline' }), 'gap-2')}
              >
                <span>Browse Catalog</span>
                <ArrowRight className="h-4 w-4" />
              </Link>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mt-8">
            <Card className="border-stone-200 bg-white/85 shadow-none">
              <CardContent className="p-5">
                <BodyText variant="muted" className="text-xs uppercase tracking-[0.18em]">Events You Can Manage</BodyText>
                <div className="mt-3 flex items-end justify-between">
                  <span className="text-3xl font-semibold text-stone-900">{stats.managedCount}</span>
                  <Calendar className="h-5 w-5 text-amber-500" />
                </div>
              </CardContent>
            </Card>

            <Card className="border-stone-200 bg-white/85 shadow-none">
              <CardContent className="p-5">
                <BodyText variant="muted" className="text-xs uppercase tracking-[0.18em]">Live On Public Catalog</BodyText>
                <div className="mt-3 flex items-end justify-between">
                  <span className="text-3xl font-semibold text-stone-900">{stats.liveCount}</span>
                  <Globe2 className="h-5 w-5 text-cyan-600" />
                </div>
              </CardContent>
            </Card>

            <Card className="border-stone-200 bg-white/85 shadow-none">
              <CardContent className="p-5">
                <BodyText variant="muted" className="text-xs uppercase tracking-[0.18em]">Total Bookings</BodyText>
                <div className="mt-3 flex items-end justify-between">
                  <span className="text-3xl font-semibold text-stone-900">{stats.bookingCount}</span>
                  <Users className="h-5 w-5 text-emerald-600" />
                </div>
              </CardContent>
            </Card>

            <Card className="border-stone-200 bg-white/85 shadow-none">
              <CardContent className="p-5">
                <BodyText variant="muted" className="text-xs uppercase tracking-[0.18em]">Total Tickets</BodyText>
                <div className="mt-3 flex items-end justify-between">
                  <span className="text-3xl font-semibold text-stone-900">{stats.ticketCount}</span>
                  <Ticket className="h-5 w-5 text-rose-500" />
                </div>
              </CardContent>
            </Card>
          </div>
        </section>

        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
          <TabsList className="grid w-full grid-cols-3 bg-white border border-stone-200 rounded-2xl p-1 h-auto">
            <TabsTrigger value="manage" className="rounded-xl py-3">Manage Events</TabsTrigger>
            <TabsTrigger value="upcoming" className="rounded-xl py-3">Upcoming Events</TabsTrigger>
            <TabsTrigger value="bookings" className="rounded-xl py-3">My Bookings</TabsTrigger>
          </TabsList>

          <TabsContent value="manage" className="space-y-6">
            <Card className="border-stone-200 shadow-none">
              <CardContent className="p-5 md:p-6 space-y-5">
                <div className="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                  <div>
                    <Heading3 className="text-stone-900 mb-1">Manage current and live events</Heading3>
                    <BodyText variant="muted">
                      This board merges your internal event list with the public catalog so current live events stop disappearing from the page you use to manage them.
                    </BodyText>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_auto] gap-3 w-full lg:w-auto lg:min-w-[420px]">
                    <div className="relative">
                      <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
                      <input
                        value={manageSearch}
                        onChange={(e) => setManageSearch(e.target.value)}
                        placeholder="Search title, venue, or description"
                        className="h-11 w-full rounded-xl border border-stone-200 bg-stone-50 pl-10 pr-4 text-sm outline-none transition focus:border-stone-400 focus:bg-white"
                      />
                    </div>
                    <AccessibleButton onClick={openCreateDialog} className="whitespace-nowrap">
                      <Plus className="h-4 w-4 mr-2" />
                      Add Event
                    </AccessibleButton>
                  </div>
                </div>

                <div className="flex flex-wrap gap-2">
                  {[
                    ['all', 'All'],
                    ['live', 'Live'],
                    ['draft', 'Draft'],
                    ['published', 'Published'],
                    ['active', 'Active'],
                  ].map(([value, label]) => (
                    <button
                      key={value}
                      type="button"
                      onClick={() => setManageFilter(value as 'all' | 'live' | 'draft' | 'published' | 'active')}
                      className={[
                        'rounded-full px-4 py-2 text-sm transition',
                        manageFilter === value
                          ? 'bg-stone-900 text-white'
                          : 'bg-white text-stone-600 border border-stone-200 hover:border-stone-300',
                      ].join(' ')}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </CardContent>
            </Card>

            {(isManagedEventsLoading || isPublicCatalogLoading) ? (
              <Card className="border-stone-200 shadow-none">
                <CardContent className="py-16 flex items-center justify-center text-stone-500">
                  <Loader2 className="h-5 w-5 mr-2 animate-spin" />
                  Loading your event board...
                </CardContent>
              </Card>
            ) : filteredEvents.length === 0 ? (
              <Card className="border-dashed border-stone-300 bg-white/70 shadow-none">
                <CardContent className="py-16 text-center">
                  <Calendar className="mx-auto h-12 w-12 text-stone-400 mb-4" />
                  <Heading3 className="mb-2">Nothing matches this view</Heading3>
                  <BodyText variant="muted" className="mb-5">
                    Try another filter or create a new event draft to start filling this board.
                  </BodyText>
                  <AccessibleButton onClick={openCreateDialog}>Create Event</AccessibleButton>
                </CardContent>
              </Card>
            ) : (
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                {filteredEvents.map((event) => {
                  const soldCount = event.totalCapacity != null && event.availableCapacity != null
                    ? Math.max(event.totalCapacity - event.availableCapacity, 0)
                    : null;
                  const progressPercent = event.totalCapacity && event.availableCapacity != null
                    ? Math.min(100, Math.max(0, ((event.totalCapacity - event.availableCapacity) / event.totalCapacity) * 100))
                    : 0;

                  return (
                    <Card key={event.id} className="overflow-hidden border-stone-200 shadow-none hover:shadow-md transition-shadow bg-white">
                      <CardContent className="p-0">
                        <div className="border-b border-stone-100 bg-[linear-gradient(135deg,_#fff7ed,_#ffffff_55%,_#ecfeff)] p-5">
                          <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                              <div className="flex flex-wrap items-center gap-2 mb-3">
                                <span className="rounded-full bg-stone-900 px-2.5 py-1 text-xs font-medium text-white">
                                  {event.inPublicCatalog ? 'Live Catalog' : 'Back Office'}
                                </span>
                                <EnhancedBadge variant="status" color={getSemanticColor(event.status, 'status')} size="sm">
                                  {event.status}
                                </EnhancedBadge>
                                {event.saleStatus && (
                                  <span className="rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-medium text-cyan-700">
                                    Sale {event.saleStatus}
                                  </span>
                                )}
                              </div>
                              <h3 className="text-xl font-semibold text-stone-900 leading-tight">{event.title}</h3>
                              <p className="mt-2 text-sm text-stone-600 line-clamp-2">
                                {event.description || 'No event description yet. Add one so the listing feels complete.'}
                              </p>
                            </div>
                            <div className="text-right shrink-0">
                              <div className="text-xs uppercase tracking-[0.18em] text-stone-400">Event ID</div>
                              <div className="text-sm font-medium text-stone-700">#{event.id}</div>
                            </div>
                          </div>
                        </div>

                        <div className="p-5 space-y-5">
                          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                            <div className="rounded-2xl bg-stone-50 p-4">
                              <div className="flex items-center gap-2 text-stone-500 mb-1">
                                <Calendar className="h-4 w-4" />
                                Date
                              </div>
                              <div className="font-medium text-stone-900">{formatEventDate(event.startDate)}</div>
                            </div>

                            <div className="rounded-2xl bg-stone-50 p-4">
                              <div className="flex items-center gap-2 text-stone-500 mb-1">
                                <MapPin className="h-4 w-4" />
                                Venue
                              </div>
                              <div className="font-medium text-stone-900">{event.venue}</div>
                            </div>
                          </div>

                          <div className="rounded-2xl border border-stone-200 p-4 space-y-3">
                            <div className="flex items-center justify-between text-sm">
                              <span className="text-stone-500">Capacity health</span>
                              <span className="font-medium text-stone-900">
                                {event.availableCapacity != null ? `${event.availableCapacity} left` : 'Capacity not available'}
                              </span>
                            </div>
                            <div className="h-2 rounded-full bg-stone-100 overflow-hidden">
                              <div
                                className="h-full rounded-full bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500"
                                style={{ width: `${progressPercent}%` }}
                              />
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-stone-600">
                              <span>Total capacity: {event.totalCapacity ?? 'N/A'}</span>
                              <span>Sold: {soldCount ?? 'N/A'}</span>
                              <span>{event.price != null ? `SGD ${event.price.toFixed(2)}` : 'Price hidden'}</span>
                            </div>
                          </div>

                          <div className="flex flex-col sm:flex-row gap-2">
                            <AccessibleButton size="sm" variant="outline" onClick={() => openEditDialog(event)} className="flex-1">
                              <Pencil className="h-4 w-4 mr-2" />
                              Edit
                            </AccessibleButton>
                            <AccessibleButton
                              size="sm"
                              variant="destructive"
                              onClick={() => handleDeleteEvent(event)}
                              loading={deletingEventId === event.id}
                              className="flex-1"
                            >
                              <Trash2 className="h-4 w-4 mr-2" />
                              Delete
                            </AccessibleButton>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  );
                })}
              </div>
            )}
          </TabsContent>

          <TabsContent value="upcoming" className="space-y-6">
            {dashboardData.upcoming_events.length === 0 ? (
              <Card className="border-stone-200 shadow-none">
                <CardContent className="py-16 text-center">
                  <Clock className="mx-auto h-12 w-12 text-stone-400 mb-4" />
                  <Heading3 className="mb-2">No upcoming events booked yet</Heading3>
                  <BodyText variant="muted" className="mb-5">
                    Your future bookings and shared events will appear here once customers start moving through the funnel.
                  </BodyText>
                  <Link
                    to="/event-management/browse"
                    className={buttonVariants({ variant: 'default' })}
                  >
                    Browse Live Events
                  </Link>
                </CardContent>
              </Card>
            ) : (
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                {dashboardData.upcoming_events.map((event) => (
                  <Card key={event.id} className="border-stone-200 shadow-none bg-white">
                    <CardContent className="p-6">
                      <div className="flex items-start justify-between gap-4 mb-5">
                        <div>
                          <h3 className="text-xl font-semibold text-stone-900">{event.name}</h3>
                          <p className="text-sm text-stone-500 mt-1">{event.venue || 'Venue TBD'}</p>
                        </div>
                        <EnhancedBadge variant="status" color={getSemanticColor(event.status, 'status')} size="sm">
                          {event.status}
                        </EnhancedBadge>
                      </div>

                      <div className="space-y-3 mb-5 text-sm text-stone-600">
                        <div className="flex items-center">
                          <Calendar className="h-4 w-4 mr-2 text-amber-500" />
                          {formatEventDate(event.start_date)}
                        </div>
                        {event.venue && (
                          <div className="flex items-center">
                            <MapPin className="h-4 w-4 mr-2 text-rose-500" />
                            {event.venue}
                          </div>
                        )}
                      </div>

                      <AccessibleButton size="sm" variant="outline" onClick={() => handleShareEvent(event.name)}>
                        <Share2 className="h-4 w-4 mr-2" />
                        Share Event
                      </AccessibleButton>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </TabsContent>

          <TabsContent value="bookings" className="space-y-6">
            {dashboardData.bookings.length === 0 ? (
              <Card className="border-stone-200 shadow-none">
                <CardContent className="py-16 text-center">
                  <Ticket className="mx-auto h-12 w-12 text-stone-400 mb-4" />
                  <Heading3 className="mb-2">No bookings yet</Heading3>
                  <BodyText variant="muted">
                    Bookings made on the storefront will appear here with ticket download actions.
                  </BodyText>
                </CardContent>
              </Card>
            ) : (
              <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                {dashboardData.bookings.map((booking) => {
                  const detail = booking as any;
                  return (
                    <Card key={booking.id} className="border-stone-200 shadow-none bg-white">
                      <CardContent className="p-6">
                        <div className="flex justify-between items-start gap-4 mb-5">
                          <div>
                            <h3 className="text-lg font-semibold text-stone-900">
                              {detail.event_title ?? `Event #${booking.event_id}`}
                            </h3>
                            <BodyText variant="muted" className="text-sm mt-1">
                              Ref: {detail.booking_reference ?? `#${booking.id}`}
                            </BodyText>
                          </div>
                          <EnhancedBadge variant="status" color={getSemanticColor(booking.status, 'status')} size="sm">
                            {booking.status}
                          </EnhancedBadge>
                        </div>

                        <div className="space-y-3 mb-5 text-sm">
                          {detail.buyer_name && (
                            <div className="flex justify-between gap-3">
                              <span className="text-stone-500">Customer</span>
                              <span className="font-medium text-stone-900">{detail.buyer_name}</span>
                            </div>
                          )}
                          {detail.event_venue && (
                            <div className="flex justify-between gap-3">
                              <span className="text-stone-500">Venue</span>
                              <span className="font-medium text-stone-900 text-right">{detail.event_venue}</span>
                            </div>
                          )}
                          {detail.event_date && (
                            <div className="flex justify-between gap-3">
                              <span className="text-stone-500">Event Date</span>
                              <span className="font-medium text-stone-900">{format(new Date(detail.event_date), 'PP')}</span>
                            </div>
                          )}
                          <div className="flex justify-between gap-3">
                            <span className="text-stone-500">Amount</span>
                            <span className="font-semibold text-stone-900">
                              {booking.total_amount === 0 ? 'FREE' : `SGD ${Number(booking.total_amount).toFixed(2)}`}
                            </span>
                          </div>
                        </div>

                        <AccessibleButton size="sm" onClick={() => handleDownloadTicket(booking.id)} className="w-full">
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
        </Tabs>

        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
          <DialogContent className="max-w-2xl rounded-3xl border-stone-200">
            <DialogHeader>
              <DialogTitle>{editingEvent ? 'Refine Event Details' : 'Create a New Event'}</DialogTitle>
              <DialogDescription>
                {editingEvent
                  ? 'Adjust the essentials that shape how this event looks and reads.'
                  : 'Start with a clean draft. You can publish and enrich the experience afterward.'}
              </DialogDescription>
            </DialogHeader>

            <form onSubmit={handleSaveEvent} className="space-y-4">
              <div>
                <label htmlFor="event-title" className="block text-sm font-medium text-stone-700 mb-1">Event Title</label>
                <input
                  id="event-title"
                  type="text"
                  value={eventForm.title}
                  onChange={(e) => handleFormChange('title', e.target.value)}
                  className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  placeholder="K-Pop Night Vol. 2"
                  required
                />
              </div>

              <div>
                <label htmlFor="event-description" className="block text-sm font-medium text-stone-700 mb-1">Description</label>
                <textarea
                  id="event-description"
                  value={eventForm.description}
                  onChange={(e) => handleFormChange('description', e.target.value)}
                  className="w-full min-h-28 rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  placeholder="Tell people what makes this event worth showing up for."
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="event-venue" className="block text-sm font-medium text-stone-700 mb-1">Venue</label>
                  <input
                    id="event-venue"
                    type="text"
                    value={eventForm.venue}
                    onChange={(e) => handleFormChange('venue', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                    placeholder="Manila Arena"
                    required
                  />
                </div>

                <div>
                  <label htmlFor="event-capacity" className="block text-sm font-medium text-stone-700 mb-1">Total Capacity</label>
                  <input
                    id="event-capacity"
                    type="number"
                    min={1}
                    value={eventForm.totalCapacity}
                    onChange={(e) => handleFormChange('totalCapacity', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                    required
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="event-date" className="block text-sm font-medium text-stone-700 mb-1">Event Start</label>
                  <input
                    id="event-date"
                    type="datetime-local"
                    value={eventForm.eventDate}
                    onChange={(e) => handleFormChange('eventDate', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                    required
                  />
                </div>

                <div>
                  <label htmlFor="event-end-date" className="block text-sm font-medium text-stone-700 mb-1">Event End</label>
                  <input
                    id="event-end-date"
                    type="datetime-local"
                    value={eventForm.endDate}
                    onChange={(e) => handleFormChange('endDate', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  />
                </div>
              </div>

              {!editingEvent && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label htmlFor="sale-start-date" className="block text-sm font-medium text-stone-700 mb-1">Ticket Sales Start</label>
                    <input
                      id="sale-start-date"
                      type="datetime-local"
                      value={eventForm.saleStartDate}
                      onChange={(e) => handleFormChange('saleStartDate', e.target.value)}
                      className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                      required
                    />
                  </div>

                  <div>
                    <label htmlFor="sale-end-date" className="block text-sm font-medium text-stone-700 mb-1">Ticket Sales End</label>
                    <input
                      id="sale-end-date"
                      type="datetime-local"
                      value={eventForm.saleEndDate}
                      onChange={(e) => handleFormChange('saleEndDate', e.target.value)}
                      className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                      required
                    />
                  </div>
                </div>
              )}

              {!editingEvent && (
                <div>
                  <label htmlFor="ticket-acquisition-mode" className="block text-sm font-medium text-stone-700 mb-1">Ticket Acquisition Mode</label>
                  <select
                    id="ticket-acquisition-mode"
                    value={eventForm.ticketAcquisitionMode}
                    onChange={(e) => handleFormChange('ticketAcquisitionMode', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  >
                    <option value="buy">Buy</option>
                    <option value="bid">Bid</option>
                  </select>
                </div>
              )}

              <div>
                <label htmlFor="event-terms" className="block text-sm font-medium text-stone-700 mb-1">Terms and Conditions</label>
                <textarea
                  id="event-terms"
                  value={eventForm.termsConditions}
                  onChange={(e) => handleFormChange('termsConditions', e.target.value)}
                  className="w-full min-h-24 rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  placeholder="Optional ticketing rules, access conditions, or booking notes."
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="event-merchandise-product-picker" className="block text-sm font-medium text-stone-700 mb-1">Select Linked Merchandise</label>
                  <select
                    id="event-merchandise-product-picker"
                    multiple
                    value={parseProductIdList(eventForm.merchandiseProductIds).map(String)}
                    onChange={(e) => handleLinkedProductSelection(
                      'merchandiseProductIds',
                      Array.from(e.target.selectedOptions, (option) => Number(option.value)).filter((value) => Number.isInteger(value) && value > 0),
                    )}
                    className="w-full min-h-36 rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  >
                    {merchandiseProductOptions.map((product) => (
                      <option key={product.id} value={product.id}>
                        #{product.id} {product.title}
                      </option>
                    ))}
                  </select>
                  <p className="mt-1 text-xs text-stone-500">
                    {isMerchandiseProductsLoading ? 'Loading merchandise options...' : 'Hold Command on macOS to select multiple products.'}
                  </p>

                  <label htmlFor="event-merchandise-products" className="block text-sm font-medium text-stone-700 mb-1">Linked Merchandise Product IDs</label>
                  <input
                    id="event-merchandise-products"
                    type="text"
                    value={eventForm.merchandiseProductIds}
                    onChange={(e) => handleFormChange('merchandiseProductIds', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                    placeholder="12, 24, 31"
                  />
                  <p className="mt-1 text-xs text-stone-500">Comma-separated product IDs for exact event merchandise.</p>
                </div>

                <div>
                  <label htmlFor="event-bundle-product-picker" className="block text-sm font-medium text-stone-700 mb-1">Select Linked Bundle Packages</label>
                  <select
                    id="event-bundle-product-picker"
                    multiple
                    value={parseProductIdList(eventForm.bundleProductIds).map(String)}
                    onChange={(e) => handleLinkedProductSelection(
                      'bundleProductIds',
                      Array.from(e.target.selectedOptions, (option) => Number(option.value)).filter((value) => Number.isInteger(value) && value > 0),
                    )}
                    className="w-full min-h-36 rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                  >
                    {bundleProductOptions.map((product) => (
                      <option key={product.id} value={product.id}>
                        #{product.id} {product.title}
                      </option>
                    ))}
                  </select>
                  <p className="mt-1 text-xs text-stone-500">
                    {isBundleProductsLoading ? 'Loading bundle options...' : 'Choose exact purchasable bundle products already published in the webshop.'}
                  </p>

                  <label htmlFor="event-bundle-products" className="block text-sm font-medium text-stone-700 mb-1">Linked Bundle Product IDs</label>
                  <input
                    id="event-bundle-products"
                    type="text"
                    value={eventForm.bundleProductIds}
                    onChange={(e) => handleFormChange('bundleProductIds', e.target.value)}
                    className="w-full rounded-xl border border-stone-200 px-3 py-2.5 outline-none transition focus:border-stone-400"
                    placeholder="45, 46"
                  />
                  <p className="mt-1 text-xs text-stone-500">Comma-separated product IDs for exact purchasable bundle packages.</p>
                </div>
              </div>

              <DialogFooter>
                <AccessibleButton type="button" variant="outline" onClick={() => setIsDialogOpen(false)}>
                  Cancel
                </AccessibleButton>
                <AccessibleButton type="submit" loading={isSavingEvent} loadingText={editingEvent ? 'Saving...' : 'Creating...'}>
                  {editingEvent ? 'Save Changes' : 'Create Event'}
                </AccessibleButton>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  );
};

export default EventsWorkspacePage;
