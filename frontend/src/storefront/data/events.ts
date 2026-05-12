import { BRANDS, type Brand, type ProductType } from '@/storefront/data/products';

export interface EventCommerce {
  brand?: Brand;
  merchType?: ProductType;
  merchSearch?: string;
  bundleSearch?: string;
  merchProductIds?: string[];
  bundleProductIds?: string[];
}

export interface Event {
  id: string;
  title: string;
  description: string;
  date: Date;
  endDate?: Date;
  image: string;
  venue: string;
  price: number;
  spotsLeft: number;
  totalSpots: number;
  ticketProductId?: string;
  isBookable?: boolean;
  commerce?: EventCommerce;
}

export type EventTimelineStatus = 'current' | 'upcoming' | 'past';

const DEFAULT_EVENT_DURATION_MS = 4 * 60 * 60 * 1000;

export function resolveEventEnd(event: Pick<Event, 'date' | 'endDate'>): Date {
  return event.endDate ?? new Date(event.date.getTime() + DEFAULT_EVENT_DURATION_MS);
}

export function getEventTimelineStatus(
  event: Pick<Event, 'date' | 'endDate'>,
  now: Date = new Date(),
): EventTimelineStatus {
  if (now < event.date) return 'upcoming';
  if (now > resolveEventEnd(event)) return 'past';
  return 'current';
}

function inferEventBrand(text: string): Brand | undefined {
  const normalizedText = text.toLowerCase();
  return BRANDS.find((brand) => normalizedText.includes(brand.toLowerCase()));
}

export function buildEventCommerce(event: Pick<Event, 'title' | 'description' | 'venue' | 'commerce'>): EventCommerce {
  const inferredBrand = inferEventBrand(`${event.title} ${event.description} ${event.venue}`);
  const brand = event.commerce?.brand ?? inferredBrand;

  return {
    brand,
    merchType: event.commerce?.merchType,
    merchSearch: event.commerce?.merchSearch ?? brand ?? event.title,
    bundleSearch: event.commerce?.bundleSearch ?? brand ?? event.title,
    merchProductIds: event.commerce?.merchProductIds,
    bundleProductIds: event.commerce?.bundleProductIds,
  };
}

export const EVENTS: Event[] = [
  {
    id: "e001",
    title: "SEVENTEEN Fan Meeting 2026",
    description: "A live fan meeting with special performances, queue-controlled entry, and exclusive campaign merchandise.",
    date: new Date("2026-05-11T10:00:00"),
    endDate: new Date("2026-05-12T22:00:00"),
    image: "https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=800&q=80",
    venue: "Marina Bay Sands, Singapore",
    price: 199.00,
    spotsLeft: 127,
    totalSpots: 500,
    isBookable: false,
    commerce: {
      brand: 'SEVENTEEN',
      merchType: 'Merchandise',
      merchSearch: 'SEVENTEEN fan meeting',
      bundleSearch: 'SEVENTEEN fan meeting bundle',
    },
  },
  {
    id: "e002",
    title: "K-POP Night Market",
    description: "Browse exclusive merchandise, trade photocards, and enjoy live DJ performances during the next campaign launch.",
    date: new Date("2026-06-21T16:00:00"),
    endDate: new Date("2026-06-21T23:00:00"),
    image: "https://images.unsplash.com/photo-1459749411175-04bf5292ceea?w=800&q=80",
    venue: "Bugis Junction, Singapore",
    price: 15.00,
    spotsLeft: 342,
    totalSpots: 400,
    isBookable: false,
    commerce: {
      merchType: 'Merchandise',
      merchSearch: 'night market merchandise',
      bundleSearch: 'night market bundle',
    },
  },
  {
    id: "e003",
    title: "Album Unboxing Party",
    description: "Join fellow fans for an exciting group unboxing of the latest releases and revisit the campaign archive.",
    date: new Date("2026-03-08T14:00:00"),
    endDate: new Date("2026-03-08T18:00:00"),
    image: "https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?w=800&q=80",
    venue: "InfinitiKpop Store, Orchard",
    price: 0,
    spotsLeft: 18,
    totalSpots: 30,
    isBookable: false,
    commerce: {
      merchType: 'Photocard',
      merchSearch: 'album unboxing photocard',
      bundleSearch: 'album unboxing bundle',
    },
  },
];
