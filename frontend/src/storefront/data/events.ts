export interface Event {
  id: string;
  title: string;
  description: string;
  date: Date;
  image: string;
  venue: string;
  price: number;
  spotsLeft: number;
  totalSpots: number;
}

export const EVENTS: Event[] = [
  {
    id: "e001",
    title: "SEVENTEEN Fan Meeting 2025",
    description: "An exclusive fan meeting experience with special performances and hi-touch sessions.",
    date: new Date("2025-02-14T19:00:00"),
    image: "https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=800&q=80",
    venue: "Marina Bay Sands, Singapore",
    price: 199.00,
    spotsLeft: 127,
    totalSpots: 500,
  },
  {
    id: "e002",
    title: "K-POP Night Market",
    description: "Browse exclusive merchandise, trade photocards, and enjoy live DJ performances.",
    date: new Date("2025-01-28T16:00:00"),
    image: "https://images.unsplash.com/photo-1459749411175-04bf5292ceea?w=800&q=80",
    venue: "Bugis Junction, Singapore",
    price: 15.00,
    spotsLeft: 342,
    totalSpots: 400,
  },
  {
    id: "e003",
    title: "Album Unboxing Party",
    description: "Join fellow fans for an exciting group unboxing of the latest releases!",
    date: new Date("2025-01-20T14:00:00"),
    image: "https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?w=800&q=80",
    venue: "InfinitiKpop Store, Orchard",
    price: 0,
    spotsLeft: 18,
    totalSpots: 30,
  },
];
