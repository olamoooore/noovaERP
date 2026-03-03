import { useQuery } from '@tanstack/react-query';
import { fetchPublicEvents, ApiEvent } from '@/storefront/lib/api';
import { Event, EVENTS } from '@/storefront/data/events';

/**
 * Map a backend ApiEvent to the storefront Event interface.
 * Keeps all existing UI components working without changes.
 */
export function mapApiEvent(apiEvent: ApiEvent): Event {
  return {
    id: String(apiEvent.id),
    title: apiEvent.title,
    description: apiEvent.description || '',
    date: new Date(apiEvent.start_date),
    image: apiEvent.image_url || 'https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=800&q=80',
    venue: apiEvent.venue || 'TBA',
    price: apiEvent.price,
    spotsLeft: apiEvent.available_capacity,
    totalSpots: apiEvent.total_capacity,
  };
}

/**
 * Fetch published events from the backend.
 * Falls back to static EVENTS if the API is unavailable.
 */
export function useStorefrontEvents() {
  return useQuery({
    queryKey: ['storefront', 'events'],
    queryFn: async () => {
      try {
        const apiEvents = await fetchPublicEvents();
        if (apiEvents.length > 0) {
          return apiEvents.map(mapApiEvent);
        }
        // If the DB has no published events, fall back to static data
        return EVENTS;
      } catch {
        // If the API fails (e.g. backend down), use static fallback
        return EVENTS;
      }
    },
    staleTime: 60 * 1000, // 1 minute
    refetchOnWindowFocus: false,
  });
}
